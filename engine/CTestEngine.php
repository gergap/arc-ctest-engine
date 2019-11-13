<?php

final class CTestEngine extends ArcanistUnitTestEngine {
    private $ctestBinary = 'ctest';
    private $covBinary = 'gcov';
    private $projectRoot;
    private $buildDir;
    private $affectedTests;
    private $ctestbuild;
    private $coverage;
    private $preBuildCommand;
    private $hasCoverageKey;

    public function getEngineConfigurationName() {
        return 'ctest-engine';
    }

    protected function supportsRunAllTests() {
        return true;
    }

    public function shouldEchoTestResults() {
        return false; // i.e. this engine does not output its own results.
    }

    private function shouldGenerateCoverage() {
        // getEnableCoverage return value meanings:
        // false: User passed --no-coverage, explicitly disabling coverage.
        // null:  User did not pass any coverage flags. Coverage should generally be enabled if
        //        available.
        // true:  User passed --coverage.
        // https://secure.phabricator.com/T10561
        $arcCoverageFlag = $this->getEnableCoverage();
        return ($arcCoverageFlag !== false) && $this->hasCoverageKey;
    }
    // helper function for loading test environment
    protected function loadEnvironment() {
        $this->projectRoot = $this->getWorkingCopy()->getProjectRoot();
        $this->buildDir = $this->projectRoot."/bld";
        $config_path = $this->getWorkingCopy()->getProjectPath('.arcconfig');

        # TODO(featherless): Find a better way to configure the unit engine, possibly via .arcunit.
        if (!Filesystem::pathExists($config_path)) {
            throw new ArcanistUsageException(
                pht(
                    "Unable to find '%s' file to configure ctest-test engine. Create an ".
                    "'%s' file in the root directory of the working copy.",
                    '.arcconfig',
                    '.arcconfig'));
        }

        // parse .arcconfig file
        $data = Filesystem::readFile($config_path);
        $config = null;
        try {
            $config = phutil_json_decode($data);
        } catch (PhutilJSONParserException $ex) {
            throw new PhutilProxyException(
                pht(
                    "Expected '%s' file to be a valid JSON file, but ".
                    "failed to decode '%s'.",
                    '.arcconfig',
                    $config_path),
                $ex);
        }

        if (!array_key_exists('unit.ctest', $config)) {
            throw new ArcanistUsageException(
                pht(
                    "Unable to find '%s' key in .arcconfig.",
                    'unit.ctest'));
        }

#        $this->ctestbuild = $config['unit.ctest']['build'];

#        $this->hasCoverageKey = array_key_exists('coverage', $config['unit.ctest']);

        if ($this->shouldGenerateCoverage()) {
            $this->ctestbuild["enableCodeCoverage"] = "YES";
            $this->coverage = $config['unit.ctest']['coverage'];
        } else {
            $this->ctestbuild["enableCodeCoverage"] = "NO";
        }

        if (array_key_exists('pre-build', $config['unit.ctest'])) {
            # override build command: this can be use to build using custom scripts (e.g. build.sh)
            # note that this command will be executed from the current buildDir, not from the project root,
            # so you might need to use something like 'cd .. && ./build.sh'.
            $this->preBuildCommand = $config['unit.ctest']['pre-build'];
        } else {
            # generic CMake way to build a tree. This will execute make, nmake, ninja, or whatever is
            # configured to build the project.
            $this->preBuildCommand = 'cmake --build .';
        }
    }

    private function getAllTests() {
        $results = array();

        // lets ctest print a list of available tests
        $future = new ExecFuture('ctest -N .');
        $future->setCWD($this->buildDir);

        list($builderror, $ctest_stdout, $ctest_stderr) = $future->resolve();
        if ($builderror !== 0) {
            return $results;
        }

        $lines = explode("\n", $ctest_stdout);
        foreach($lines AS $line) {
            $ret = preg_match("/Test +#\d+: (\w+)/", $line, $captures);
            if ($ret != 1) {
                continue;
            }
            // add test to results
            array_push($results, $captures[1]);
        }

        return $results;
    }

    private function getTestsForPaths() {
        # just a test hack
        $look_here = $this->getPaths();
        $run_tests = array();

        foreach($look_here as $path_info) {
            if ($path_info == 'src/uabase') {
                return ['test_variant', 'test_string'];
            }
        }

        // TODO: add a smart way to detect what needs to be tests
        // for now we run simply all tests
        return $this->getAllTests();
    }

    private function run_one_test($name) {
        // run specified test using a RegEx match
        $future = new ExecFuture('ctest -R ^'.$name.'$ .');
        $future->setCWD($this->buildDir);

        // wait for future to complete
        list($builderror, $ctest_stdout, $ctest_stderr) = $future->resolve();

        if ($builderror !== 0) {
            return array(id(new ArcanistUnitTestResult())
                ->setName("CTest engine")
                ->setUserData($ctest_stderr)
                ->setResult(ArcanistUnitTestResult::RESULT_BROKEN));
        }

        // parse test output
        $lines = explode("\n", $ctest_stdout);
        foreach($lines AS $line) {
            $ret = preg_match("/(\d+)\/(\d+) Test +#(\d+): (\w+) \.+ + (Passed|Failed) + ([0-9.]+) sec/", $line, $captures);
            if ($ret != 1) {
                continue;
            }

            $index    = $captures[1];
            $count    = $captures[2];
            $testnum  = $captures[3];
            $name     = $captures[4];
            $status   = $captures[5];
            $duration = floatval($captures[6]);

            $result = new ArcanistUnitTestResult();
            $result->setName($name);
            if ($status == 'Passed') {
                $result->setResult(ArcanistUnitTestResult::RESULT_PASS);
            } else {
                $result->setResult(ArcanistUnitTestResult::RESULT_FAIL);
            }
            $result->setDuration($duration);
            # print result
            if ($this->renderer) {
                print $this->renderer->renderUnitResult($result);
            } else {
                print("error: not renderer set.\n");
            }

            // we don't expect more than one result
            return $result;
        }

        return null;
    }

    public function run() {
        $results = array();

        $this->loadEnvironment();

        if ($this->getRunAllTests()) {
            # execute all tests
            $testnames = $this->getAllTests();
        } else {
            # execute only the tests for changed code
            $testnames = $this->getTestsForPaths();
        }

        // build project
        if (!empty($this->preBuildCommand)) {
            $future = new ExecFuture($this->preBuildCommand);
            $future->setCWD(Filesystem::resolvePath($this->buildDir));
            $future->resolvex();
        }

        // run all configured tests
        foreach($testnames AS $testname) {
            $result = $this->run_one_test($testname);
            array_push($results, $result);
        }

        return $results;
    }
}
