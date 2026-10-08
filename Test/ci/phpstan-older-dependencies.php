<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 *
 * PHPStan ignores that apply only when the harness resolved older dependency releases (PHP 8.1 resolves to
 * Magento 2.4.7). Each rule is added only when the installed code shows the difference, so the newest stack is
 * analysed without them.
 */
declare(strict_types=1);

$vendor = __DIR__ . '/vendor';
$ignoreErrors = [];

// Magento 2.4.7 documents the message parameter as string only; Phrase works at runtime and later releases
// document string|\Stringable.
$messageManager = (string) @file_get_contents($vendor . '/magento/framework/Message/ManagerInterface.php');
if (str_contains($messageManager, '@param string $message')) {
    $ignoreErrors[] = [
        'message' => '#^Parameter \#1 \$message of method Magento\\\\Framework\\\\Message\\\\ManagerInterface::'
            . 'add(Error|Notice|Success|Warning)Message\(\) expects string, Magento\\\\Framework\\\\Phrase given\.$#',
    ];
}

return ['parameters' => ['ignoreErrors' => $ignoreErrors]];
