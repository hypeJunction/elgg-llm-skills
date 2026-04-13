<?php
return [
    \MyPlugin\MyService::class => \DI\object(\MyPlugin\MyService::class)
        ->constructorParameter('table', 'mytable'),
    \MyPlugin\OtherService::class => \DI\object(\MyPlugin\OtherService::class),
];
