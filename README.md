ParallelProcessing
==================

Php library for easy object-oriented parallel processing.

Examples can be found here - https://github.com/gerkirill/ParallelProcessingExample

This library does not use pcntl_fork. It just starts separate processes with proc_open and thus can be used from script running under Apache web server (in a case you need that).