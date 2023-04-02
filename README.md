# PHPUnit-Parallel-Extension

PHPUnit-Parallel-Extension is an extension to PHPUnit with leverages the parallel extension to run test cases in parallel. PARALLEL!<br><br>
Wait, doesnt [Pest](https://pestphp.com/) or [ParaTest](https://github.com/paratestphp/paratest) already do this??<br>
Yes, but they do it with multiple processes. This means much more overhead for interprocess communication. Threads share an address space and thus can talk to each other directly, which should be faster. Moreover, this is an extension of phpunit, so no new tool needs to be used. It is an addon. Just try it out!

## Installation

- Use [Composer](https://getcomposer.org/) to require the extension.
- Make sure you use a php zts build and install the parallel extension, like so:
```
FROM php:8.1-zts-alpine3.17 as php
RUN apk add --no-cache $PHPIZE_DEPS \
    && \
    pecl install parallel-1.2.1 \
    && \
    docker-php-ext-enable parallel \
    && \
    apk del $PHPIZE_DEPS
```
- Make sure you use the exact version of phpunit that is required by this extension (look into composer.json or do not install phpunit "on your own")
- Be aware: Issues can be present, please just create an issue if you face any due to php threads and standard library calls which do not behave as they should
  - Also make sure that tests do not depend on the same database, as deadlocks or other funny things can occur. Maybe look at the memory [storage engine](https://mariadb.com/kb/en/memory-storage-engine/) or wait till someone writes a storage engine designed for this kind of testing [;)](https://github.com/florianPat/mariadb-server) 
- Configure the extension
- Go blazingly fast!!

## Parameter

- nCores: Number of threads to start

## Configuration

Enable the extension in your phpunit.xml file:
```
<extensions>
    <bootstrap class="ParallelExtension\ExtensionBootstrap">
        <parameter name="nCores" value="2"/>
    </bootstrap>
</extensions>
```
