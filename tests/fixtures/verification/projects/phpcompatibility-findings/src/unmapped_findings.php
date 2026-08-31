<?php

function unmapped_evidence(string $text): void
{
    utf8_encode($text);
    utf8_decode($text);
    split(',', $text);
}
