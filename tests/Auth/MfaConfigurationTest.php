<?php

namespace Tests\Auth;

use BookStack\Auth\Access\Mfa\MfaValue;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class MfaConfigurationTest extends TestCase
{

    public function test_totp_setup()
    {
        $editor = $this->getEditor();
        $this->assertDatabaseMissing('mfa_values', ['user_id' => $editor->id]);

        // Setup page state
        $resp = $this->actingAs($editor)->get('/mfa/setup');
        $resp->assertElementContains('a[href$="/mfa/totp-generate"]', 'Setup');

        // Generate page access
        $resp = $this->get('/mfa/totp-generate');
        $resp->assertSee('Mobile App Setup');
        $resp->assertSee('Verify Setup');
        $resp->assertElementExists('form[action$="/mfa/totp-confirm"] button');
        $this->assertSessionHas('mfa-setup-totp-secret');
        $svg = $resp->getElementHtml('#main-content .card svg');

        // Validation error, code should remain the same
        $resp = $this->post('/mfa/totp-confirm', [
            'code' => 'abc123',
        ]);
        $resp->assertRedirect('/mfa/totp-generate');
        $resp = $this->followRedirects($resp);
        $resp->assertSee('The provided code is not valid or has expired.');
        $revisitSvg = $resp->getElementHtml('#main-content .card svg');
        $this->assertTrue($svg === $revisitSvg);

        // Successful confirmation
        $google2fa = new Google2FA();
        $secret = decrypt(session()->get('mfa-setup-totp-secret'));
        $otp = $google2fa->getCurrentOtp($secret);
        $resp = $this->post('/mfa/totp-confirm', [
            'code' => $otp,
        ]);
        $resp->assertRedirect('/mfa/setup');

        // Confirmation of setup
        $resp = $this->followRedirects($resp);
        $resp->assertSee('Multi-factor method successfully configured');
        $resp->assertElementContains('a[href$="/mfa/totp-generate"]', 'Reconfigure');

        $this->assertDatabaseHas('mfa_values', [
            'user_id' => $editor->id,
            'method' => 'totp',
        ]);
        $this->assertFalse(session()->has('mfa-setup-totp-secret'));
        $value = MfaValue::query()->where('user_id', '=', $editor->id)
            ->where('method', '=', 'totp')->first();
        $this->assertEquals($secret, decrypt($value->value));
    }

    public function test_backup_codes_setup()
    {
        $editor = $this->getEditor();
        $this->assertDatabaseMissing('mfa_values', ['user_id' => $editor->id]);

        // Setup page state
        $resp = $this->actingAs($editor)->get('/mfa/setup');
        $resp->assertElementContains('a[href$="/mfa/backup-codes-generate"]', 'Setup');

        // Generate page access
        $resp = $this->get('/mfa/backup-codes-generate');
        $resp->assertSee('Backup Codes');
        $resp->assertElementContains('form[action$="/mfa/backup-codes-confirm"]', 'Confirm and Enable');
        $this->assertSessionHas('mfa-setup-backup-codes');
        $codes = decrypt(session()->get('mfa-setup-backup-codes'));
        // Check code format
        $this->assertCount(16, $codes);
        $this->assertEquals(16*11, strlen(implode('', $codes)));
        // Check download link
        $resp->assertSee(base64_encode(implode("\n\n", $codes)));

        // Confirm submit
        $resp = $this->post('/mfa/backup-codes-confirm');
        $resp->assertRedirect('/mfa/setup');

        // Confirmation of setup
        $resp = $this->followRedirects($resp);
        $resp->assertSee('Multi-factor method successfully configured');
        $resp->assertElementContains('a[href$="/mfa/backup-codes-generate"]', 'Reconfigure');

        $this->assertDatabaseHas('mfa_values', [
            'user_id' => $editor->id,
            'method' => 'backup_codes',
        ]);
        $this->assertFalse(session()->has('mfa-setup-backup-codes'));
        $value = MfaValue::query()->where('user_id', '=', $editor->id)
            ->where('method', '=', 'backup_codes')->first();
        $this->assertEquals($codes, json_decode(decrypt($value->value)));
    }

    public function test_backup_codes_cannot_be_confirmed_if_not_previously_generated()
    {
        $resp = $this->asEditor()->post('/mfa/backup-codes-confirm');
        $resp->assertStatus(500);
    }

}