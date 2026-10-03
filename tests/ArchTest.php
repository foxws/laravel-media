<?php

declare(strict_types=1);

arch()->preset()->php();

arch()->preset()->security();

arch('it will not use dd(), ddd(), env(), or exit()')
    ->expect(['dd', 'ddd', 'env', 'exit'])
    ->each->not->toBeUsed();

arch('the package source declares strict types')
    ->expect('Foxws\\Media')
    ->toUseStrictTypes();

arch('the core does not depend on tools, encoders, packagers or delivery')
    ->expect(['Foxws\\Media\\Filesystem', 'Foxws\\Media\\Executables', 'Foxws\\Media\\Process', 'Foxws\\Media\\Probe', 'Foxws\\Media\\Encryption'])
    ->not->toUse(['Foxws\\Media\\FFMpeg', 'Foxws\\Media\\Encoding', 'Foxws\\Media\\Packaging', 'Foxws\\Media\\Delivery']);
