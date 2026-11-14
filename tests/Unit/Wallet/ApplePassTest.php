<?php

declare(strict_types=1);

use App\Support\Branding\Contrast;
use App\Support\Wallet\ApplePassJson;
use App\Support\Wallet\PassCode;
use App\Support\Wallet\PassImages;
use App\Support\Wallet\SignPkpass;
use Tests\Support\WalletCertificates;

/*
|--------------------------------------------------------------------------
| The Apple Wallet pass (CHW-38, design 25)
|--------------------------------------------------------------------------
|
| Its code (PW1.{serial}.{mac}), its pass.json, the strip and the signed
| .pkpass. The certificates are throwaway ones made for the test.
|
*/

beforeEach(function (): void {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
    $this->pass = [
        'passTypeId' => 'pass.test.punchcard', 'teamId' => 'TEAMID1234', 'serial' => str_repeat('a1', 13), 'authToken' => str_repeat('t', 40),
        'webServiceUrl' => 'https://app.punchcard.test/wallet/apple', 'organizationName' => 'Café Hafa', 'cardName' => 'Coffee card',
        'rewardText' => 'A free coffee', 'terms' => 'One stamp per drink.', 'stampsCollected' => 7, 'stampsRequired' => 10,
        'brandColor' => '#0F5E63', 'brandForeground' => null, 'memberName' => 'Salma T.', 'memberNumber' => 4827,
        'cardUrl' => 'https://app.punchcard.test/app/cards/12', 'locale' => 'en',
    ];
});

it('signs a pass code nobody can change or make, with one spelling', function (): void {
    $serial = PassCode::newSerial();
    $code = PassCode::of($serial);
    $fields = PassCode::fields($code);

    expect($serial)->toMatch('/^[0-9a-f]{26}$/')
        ->and($fields)->toBe(['serial' => $serial, 'mac' => substr($code, 31)])
        ->and(PassCode::authentic($fields))->toBeTrue();

    // Another pass's serial with this mac, a mac changed, other spellings: not a code, or not an authentic one.
    $other = PassCode::fields('PW1.'.str_repeat('b', 26).'.'.substr($code, 31));
    $changed = PassCode::fields(substr($code, 0, -1).(str_ends_with($code, 'A') ? 'B' : 'A'));

    expect(PassCode::authentic($other))->toBeFalse()
        ->and(PassCode::authentic($changed))->toBeFalse()
        ->and(PassCode::fields(strtoupper($code)))->toBeNull()
        ->and(PassCode::fields($code.' '))->toBeNull()
        ->and(PassCode::fields('PC1.'.substr($code, 4)))->toBeNull();
});

it('writes the card as a store card: stamps, reward, member and the pass code', function (): void {
    $json = ApplePassJson::build($this->pass);

    expect($json)->toMatchArray([
        'formatVersion' => 1, 'passTypeIdentifier' => 'pass.test.punchcard', 'teamIdentifier' => 'TEAMID1234',
        'serialNumber' => $this->pass['serial'], 'organizationName' => 'Café Hafa', 'logoText' => 'Café Hafa',
        'description' => 'Coffee card at Café Hafa', 'sharingProhibited' => true,
        'webServiceURL' => 'https://app.punchcard.test/wallet/apple', 'authenticationToken' => str_repeat('t', 40),
    ])
        ->and($json['storeCard']['headerFields'][0])->toBe(['key' => 'stamps', 'label' => 'Stamps', 'value' => '7/10', 'changeMessage' => 'Stamps: %@'])
        ->and($json['storeCard']['secondaryFields'])->toBe([
            ['key' => 'reward', 'label' => 'Reward', 'value' => 'A free coffee'],
            ['key' => 'member', 'label' => 'Member', 'value' => 'Salma T.', 'textAlignment' => 'PKTextAlignmentRight'],
        ])
        ->and(array_column($json['storeCard']['backFields'], 'value'))->toBe(['Coffee card', 'One stamp per drink.', 'https://app.punchcard.test/app/cards/12'])
        ->and($json['barcodes'])->toBe([[
            'format' => 'PKBarcodeFormatQR', 'message' => PassCode::of($this->pass['serial']), 'messageEncoding' => 'iso-8859-1', 'altText' => 'Member 4827',
        ]]);
});

it('paints the pass as the card: its colour, readable text and stamp-coloured labels', function (): void {
    $json = ApplePassJson::build($this->pass);
    $foreground = Contrast::readableForeground('#0F5E63');

    expect($json['backgroundColor'])->toBe('rgb(15, 94, 99)')
        ->and($json['foregroundColor'])->toBe(ApplePassJson::rgb($foreground))
        ->and($json['labelColor'])->toBe(ApplePassJson::rgb(Contrast::stampColor('#0F5E63', $foreground)))
        // No colour: punchcard's espresso; a stored text colour that doesn't read is not used.
        ->and(ApplePassJson::colours(null, null)[0])->toBe(Contrast::ESPRESSO)
        ->and(ApplePassJson::colours('#0F5E63', '#0F5E64')[1])->toBe($foreground)
        ->and(ApplePassJson::colours('#a63d2a', '#FBF6EE'))->toBe(['#A63D2A', '#FBF6EE', Contrast::stampColor('#A63D2A', '#FBF6EE')]);
});

it('leaves out the web service without HTTPS, and the member and terms when there are none', function (): void {
    $json = ApplePassJson::build([...$this->pass, 'webServiceUrl' => 'http://localhost:8000/wallet/apple', 'memberName' => null, 'terms' => '  ']);

    expect($json)->not->toHaveKeys(['webServiceURL', 'authenticationToken'])
        ->and(array_column($json['storeCard']['secondaryFields'], 'key'))->toBe(['reward'])
        ->and(array_column($json['storeCard']['backFields'], 'key'))->toBe(['card', 'open']);
});

it('writes the pass in the customer\'s language', function (): void {
    $fr = ApplePassJson::build([...$this->pass, 'locale' => 'fr']);
    $ar = ApplePassJson::build([...$this->pass, 'locale' => 'ar']);

    expect($fr['storeCard']['headerFields'][0]['label'])->toBe('Tampons')
        ->and($fr['barcodes'][0]['altText'])->toBe('Membre 4827')
        ->and($ar['storeCard']['headerFields'][0]['label'])->toBe('الأختام')
        ->and($ar['storeCard']['secondaryFields'][0]['label'])->toBe('المكافأة');
});

it('fits the stamps in the strip, five to ten a row', function (): void {
    expect(PassImages::grid(10, 343, 120)[0])->toBe(5)
        ->and(PassImages::grid(5, 343, 120)[0])->toBe(5)
        ->and(PassImages::grid(50, 343, 120))->toMatchArray([0 => 10, 1 => 5]);
});

it('draws the strip at Wallet\'s size, as a PNG', function (): void {
    $look = ['stampsCollected' => 7, 'stampsRequired' => 10, 'stampStyle' => 'star', 'background' => '#0F5E63', 'foreground' => '#FBF6EE', 'stamp' => '#F2A541', 'rtl' => false, 'logoPath' => null];

    foreach ([1 => [375, 144], 3 => [1125, 432]] as $scale => $size) {
        $size2 = getimagesizefromstring(PassImages::strip($look, $scale));

        expect([$size2[0] ?? null, $size2[1] ?? null, $size2['mime'] ?? null])->toBe([...$size, 'image/png']);
    }

    // Arabic fills from the right: the first stamp's side differs.
    $ltr = imagecreatefromstring(PassImages::strip([...$look, 'stampsCollected' => 1], 1));
    $rtl = imagecreatefromstring(PassImages::strip([...$look, 'stampsCollected' => 1, 'rtl' => true], 1));

    // Inside the first slot, beside its star (10 stamps: 5 a row, 53 pt each, the first centred at 54, 39): the stamp in English, a slot still to come in Arabic.
    $stamp = imagecolorsforindex($ltr, imagecolorat($ltr, 34, 39));
    $slot = imagecolorsforindex($rtl, imagecolorat($rtl, 34, 39));

    expect([$stamp['red'], $stamp['green'], $stamp['blue']])->toBe([0xF2, 0xA5, 0x41])
        ->and([$slot['red'], $slot['green'], $slot['blue']])->not->toBe([0xF2, 0xA5, 0x41]);
});

it('signs the pass: a manifest of every file, and Apple\'s detached signature over it', function (): void {
    $certificates = WalletCertificates::install();

    try {
        $files = ['pass.json' => json_encode(ApplePassJson::build($this->pass)), 'icon.png' => "\x89PNG test"];
        $path = tempnam(sys_get_temp_dir(), 'pkpass-test');
        file_put_contents((string) $path, SignPkpass::handle($files));
        $zip = new ZipArchive;
        $zip->open((string) $path);
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $signature = (string) $zip->getFromName('signature');
        $names = array_map(fn (int $i): string => (string) $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
        $zip->close();
        @unlink((string) $path);

        expect($names)->toEqualCanonicalizing(['pass.json', 'icon.png', 'manifest.json', 'signature'])
            ->and($manifest)->toBe(['pass.json' => sha1($files['pass.json']), 'icon.png' => sha1($files['icon.png'])]);

        // A PKCS#7 signature in DER, carrying the pass certificate and the WWDR one.
        $pem = "-----BEGIN PKCS7-----\n".chunk_split(base64_encode($signature), 64, "\n")."-----END PKCS7-----\n";
        expect(openssl_pkcs7_read($pem, $carried))->toBeTrue()->and($carried)->toHaveCount(2);

        // It signs this manifest, and no other (checked by the openssl command, when the machine has one).
        $openssl = trim((string) shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where openssl 2>NUL' : 'command -v openssl 2>/dev/null'));

        if ($openssl !== '') {
            $dir = $certificates->directory;
            file_put_contents($dir.'/signature.der', $signature);
            file_put_contents($dir.'/manifest.json', json_encode($manifest, JSON_UNESCAPED_SLASHES));
            file_put_contents($dir.'/other.json', '{}');
            $verify = fn (string $content): string => (string) shell_exec('openssl cms -verify -noverify -binary -inform DER -in '.escapeshellarg($dir.'/signature.der').' -content '.escapeshellarg($content).' -out '.escapeshellarg($dir.'/out').' 2>&1');

            expect($verify($dir.'/manifest.json'))->toContain('Verification successful')
                ->and($verify($dir.'/other.json'))->toContain('Verification failure');
        }
    } finally {
        $certificates->remove();
    }
});

it('refuses to sign without a readable certificate', function (): void {
    config(['punchcard.wallet.apple' => ['pass_type_id' => 'p', 'team_id' => 't', 'cert_path' => sys_get_temp_dir().'/missing.p12', 'cert_password' => '', 'wwdr_cert_path' => sys_get_temp_dir().'/missing.pem']]);

    SignPkpass::handle(['pass.json' => '{}']);
})->throws(RuntimeException::class, 'certificate can\'t be read');
