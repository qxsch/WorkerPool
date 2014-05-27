Please ensure, that:
 1. you do not have any open handles (DB connections, Files, ...) when:
   1. executing the create method
   1. executing the run method for dynamic pools
 1. you are not using objects in the child process, that are going to close shared resources (f.e. in the __destruct method)


In case you need handles (f.e. DB connections), open them in the children in the onProcessCreate method and close them in the onProcessDestroy method

