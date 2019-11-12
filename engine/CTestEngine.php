<?php

final class CTestEngine extends ArcanistUnitTestEngine {
    private $ctestBinary = 'ctest';
    private $covBinary = 'gcov';
    private $projectRoot;
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
        return true; // i.e. this engine does not output its own results.
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
            $this->preBuildCommand = $config['unit.ctest']['pre-build'];
        }
    }

    public function run() {
        $this->loadEnvironment();

        if (!$this->getRunAllTests()) {
            $paths = $this->getPaths();
            if (empty($paths)) {
                return array();
            }
        }

        $ctestargs = array();
        foreach ($this->ctestbuild as $key => $value) {
            $ctestargs []= "-$key \"$value\"";
        }

        if (!empty($this->preBuildCommand)) {
            $future = new ExecFuture($this->preBuildCommand);
            $future->setCWD(Filesystem::resolvePath($this->getWorkingCopy()->getProjectRoot()));
            $future->resolvex();
        }

        // Build and run unit tests
        #$future = new ExecFuture('%C %C test',
        #    $this->ctestbuildBinary, implode(' ', $ctestargs));
        $future = new ExecFuture('ctest .');
        $future->setCWD($this->projectRoot . '/bld');

        list($builderror, $ctest_stdout, $ctest_stderr) = $future->resolve();

        if ($builderror !== 0) {
            return array(id(new ArcanistUnitTestResult())
                ->setName("CTest engine")
                ->setUserData($xcbuild_stderr)
                ->setResult(ArcanistUnitTestResult::RESULT_BROKEN));
        }

        $lines = explode("\n", $ctest_stdout);
        $results = array();
        foreach($lines AS $line) {
            $ret = preg_match("/(\d+)\/(\d+) Test +#\d+: (\w+) \.+ + (Passed|Failed) + ([0-9.]+) sec/", $line, $captures);
            if ($ret != 1) {
                continue;
            }

            $index    = $captures[1];
            $count    = $captures[2];
            $name     = $captures[3];
            $status   = $captures[4];
            $duration = floatval($captures[5]);

            $result = new ArcanistUnitTestResult();
            $result->setName($name);
            if ($status == 'Passed') {
                $result->setResult(ArcanistUnitTestResult::RESULT_PASS);
            } else {
                $result->setResult(ArcanistUnitTestResult::RESULT_FAIL);
            }
            $result->setDuration($duration);

            array_push($results, $result);
        }
        return $results;


        // Extract coverage information
        /*
        $coverage = null;
        if ($builderror === 0 && $this->shouldGenerateCoverage()) {
            // Get the OBJROOT
            $future = new ExecFuture('%C %C -showBuildSettings test',
                $this->ctestbuildBinary, implode(' ', $ctestargs));
            $future->setCWD(Filesystem::resolvePath($this->getWorkingCopy()->getProjectRoot()));
            list(, $settings_stdout, ) = $future->resolve();
            if (!preg_match('/OBJROOT = (.+)/', $settings_stdout, $matches)) {
                throw new Exception('Unable to find OBJROOT configuration.');
            }
            $objroot = $matches[1];
            $future = new ExecFuture("find %C -name Coverage.profdata", $objroot);
            list(, $coverage_stdout, ) = $future->resolve();
            $profdata_path = explode("\n", $coverage_stdout)[0];
            $future = new ExecFuture("find %C | grep %C", $objroot, $this->coverage['product']);
            list(, $product_stdout, ) = $future->resolve();
            $product_path = explode("\n", $product_stdout)[0];
            $future = new ExecFuture('%C show -use-color=false -instr-profile "%C" "%C"',
                $this->covBinary, $profdata_path, $product_path);
            $future->setCWD(Filesystem::resolvePath($this->getWorkingCopy()->getProjectRoot()));
            try {
                list($coverage, $coverage_error) = $future->resolvex();
            } catch (CommandException $exc) {
                if ($exc->getError() != 0) {
                    throw $exc;
                }
            }
        }
         */
        /*
        public function run() {
            $sample_results = array();

            # example for a passed test result
            $result_success = new ArcanistUnitTestResult();
            $result_success->setName("A successful test");
            $result_success->setResult(ArcanistUnitTestResult::RESULT_PASS);
            $result_success->setDuration(1);

            # example for a failed test result
            $result_failure = new ArcanistUnitTestResult();
            $result_failure->setName("A failed test");
            $result_failure->setResult(ArcanistUnitTestResult::RESULT_FAIL);
            $result_failure->setUserData(
                "This test failed, because we wanted it to fail."
            );

            # add both results
            $sample_results[] = $result_success;
            $sample_results[] = $result_failure;

            # return the result set
            return $sample_results;
        }
*/

    }
}
