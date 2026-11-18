<?php

return [

    'failed' => 'These credentials do not match our records.',
    'password' => 'The provided password is incorrect.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',
    'inactive' => 'This account has been deactivated. Please contact HR.',
    'access_ended' => 'Your access to this organisation has ended.',

    'fields' => [
        'name' => 'Name',
        'email' => 'Email address',
        'password' => 'Password',
        'current_password' => 'Current password',
        'new_password' => 'New password',
        'confirm_password' => 'Confirm password',
        'show_password' => 'Show password',
        'hide_password' => 'Hide password',
    ],

    'login' => [
        'head' => 'Log in',
        'title' => 'Log in to MyHRMIS',
        'description' => 'Enter your email and password to continue',
        'forgot' => 'Forgot password?',
        'remember' => 'Remember me',
        'submit' => 'Log in',
    ],

    'forgot' => [
        'title' => 'Forgot password',
        'description' => 'Enter your email to receive a password reset link',
        'submit' => 'Email password reset link',
        'return_to' => 'Or, return to',
    ],

    'reset' => [
        'title' => 'Reset password',
        'description' => 'Enter your new password below',
        'submit' => 'Reset password',
    ],

    'confirm' => [
        'title' => 'Confirm your password',
        'description' => 'This is a secure area. Please confirm your password before continuing.',
        'submit' => 'Confirm password',
    ],

    'settings' => [
        'title' => 'Account settings',
        'description' => 'Manage your profile and account settings',
        'nav_profile' => 'Profile',
        'nav_password' => 'Password',
        'nav_two_factor' => 'Two-factor authentication',
        'nav_appearance' => 'Appearance',
        'profile_title' => 'Profile settings',
        'profile_info' => 'Profile information',
        'profile_description' => 'Update your name and email address',
        'password_title' => 'Password settings',
        'update_password' => 'Update password',
        'password_description' => 'Use a long, random password to keep your account secure',
        'save_password' => 'Save password',
        'appearance_title' => 'Appearance settings',
        'appearance_description' => 'Update the appearance of your account',
        'appearance_light' => 'Light',
        'appearance_dark' => 'Dark',
        'appearance_system' => 'System',
    ],

    'two_factor' => [
        'head' => 'Two-factor authentication',
        'manage' => 'Manage your two-factor authentication settings',
        'enabled' => 'Enabled',
        'disabled' => 'Disabled',
        'enable' => 'Enable 2FA',
        'recovery_title' => 'Recovery code',
        'recovery_description' => 'Confirm access to your account by entering one of your emergency recovery codes.',
        'code_title' => 'Authentication code',
        'code_description' => 'Enter the authentication code from your authenticator app.',
        'use_code' => 'log in using an authentication code',
        'use_recovery' => 'log in using a recovery code',
        'or_you_can' => 'or you can',
        'enter_recovery' => 'Enter recovery code',
        'enabled_title' => 'Two-factor authentication enabled',
        'enabled_description' => 'Two-factor authentication is now enabled. Scan the QR code or enter the setup key in your authenticator app.',
        'verify_title' => 'Verify authentication code',
        'verify_description' => 'Enter the 6-digit code from your authenticator app',
        'enable_title' => 'Enable two-factor authentication',
        'enable_description' => 'To finish, scan the QR code or enter the setup key in your authenticator app',
        'manual' => 'or, enter the code manually',
        'recovery_codes' => '2FA recovery codes',
        'recovery_codes_description' => 'Recovery codes let you regain access if you lose your 2FA device. Keep them in a secure password manager.',
        'hide_codes' => 'Hide recovery codes',
        'view_codes' => 'View recovery codes',
        'regenerate' => 'Regenerate codes',
        'codes_note' => 'Each recovery code can be used once and is removed after use. Regenerate codes above if you need more.',
    ],

];
