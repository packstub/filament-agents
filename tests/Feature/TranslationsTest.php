<?php

$locales = collect(glob(__DIR__.'/../../resources/lang/*.json'))->map(fn ($file) => basename($file, '.json'))->reject('ru')->values()->all();

function langJson(string $locale): array
{
    return json_decode(file_get_contents(__DIR__.'/../../resources/lang/'.$locale.'.json'), true, flags: JSON_THROW_ON_ERROR);
}

function placeholders(string $text): array
{
    preg_match_all('/:[a-z_]+/i', $text, $found);
    $found = array_unique($found[0]);
    sort($found);

    return $found;
}

it('ships he and ar', function () {
    expect(glob(__DIR__.'/../../resources/lang/*.json'))->toContain(__DIR__.'/../../resources/lang/he.json', __DIR__.'/../../resources/lang/ar.json');
});

it('has every key of ru.json, no more, and the same placeholders, in :dataset', function (string $locale) {
    $ru = langJson('ru');
    $translated = langJson($locale);

    expect(array_keys($translated))->toEqualCanonicalizing(array_keys($ru));

    foreach ($translated as $key => $text) {
        expect(is_string($text) && $text !== '')->toBeTrue()
            ->and(str_contains($key, '|') ? array_diff(placeholders($text), placeholders($key)) : placeholders($text))
            ->toBe(str_contains($key, '|') ? [] : placeholders($ru[$key]), $locale.': '.$key);
    }
})->with($locales);

it('picks the right plural form in he and ar', function () {
    $key = '{0} No rows|{1} :count row|[2,*] :count rows';

    app()->setLocale('he');
    expect(trans_choice($key, 0))->toBe('אין שורות')
        ->and(trans_choice($key, 1))->toBe('שורה אחת')
        ->and(trans_choice($key, 5, ['count' => 5]))->toBe('5 שורות');

    app()->setLocale('ar');
    expect(trans_choice($key, 2))->toBe('صفان')
        ->and(trans_choice($key, 5, ['count' => 5]))->toBe('5 صفوف')
        ->and(trans_choice($key, 11, ['count' => 11]))->toBe('11 صفًا');
});
