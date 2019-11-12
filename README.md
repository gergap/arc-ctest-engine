# ctest-engine for arc

ctest-engine is a test engine for use with Phabricator's `arc` command line tool.

## Features

Running CMake/CTest baed unit tests using arc:

```
$> arc unit
   PASS   <1ms★  test_ref
   PASS   <1ms★  test_function_macro
   PASS  120ms   test_time
   PASS   <1ms★  test_ipcmem
   PASS   <1ms★  test_shutdown
   PASS   <1ms★  test_memset
   PASS   <1ms★  test_atomic
   PASS   <1ms★  test_semaphore
   PASS   <1ms★  test_process
   PASS   10ms★  test_file
   PASS   <1ms★  test_md5
...
```

# TODOs

* add Coverage Support
* add build project support if Makefile is missing
  could be done using cmake itself or build.sh
  best would be to make this configurable using .arcconfig/.arcunit

