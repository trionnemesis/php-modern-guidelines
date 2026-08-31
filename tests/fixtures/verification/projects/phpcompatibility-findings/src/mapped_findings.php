<?php

$name = 'value';
$one = 'x';
echo "${name}";
echo "${$one}";

class TypedConstantHolder
{
    const string LABEL = 'label';
}

function implicitly_nullable(string $text = null): void
{
    echo $text;
}

function new_functions(array $items): void
{
    json_validate('{}');
    array_find($items, static fn ($item) => $item === 1);
    array_find_key($items, static fn ($item) => $item === 1);
    array_any($items, static fn ($item) => $item === 1);
    array_all($items, static fn ($item) => $item === 1);
    array_first($items);
    array_last($items);
}

function ini_directive(): void
{
    ini_set('mysqli.reconnect', '1');
}

function curl_lifecycle(): void
{
    $handle = curl_init('https://example.com');
    curl_close($handle);
}
