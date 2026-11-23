<?php
declare(strict_types=1);

function settings_view(array $state): void
{
    $settings = $state['settings'];
    card('Organization');
    post_form('settings.save');
    field('company', 'Company name', $settings['company']);
    field('workspace', 'Workspace name', $settings['workspace']);
    choice('currency', 'Currency', options(['PHP', 'USD', 'EUR', 'GBP', 'SGD', 'AUD', 'CAD', 'JPY', 'INR']), $settings['currency']);
    field('taxRate', 'Tax rate (%)', $settings['taxRate'], 'number');
    field('benefits', 'Benefits deduction', $settings['benefits'], 'number');
    foreach (LEAVE_TYPES as $type) field('allowances[' . $type . ']', $type . ' allowance', $settings['allowances'][$type] ?? 0, 'number');
    end_form('Save organization');
    echo '</section>';
    card('Account');
    post_form('profile.save');
    field('name', 'Display name', $state['user']['name']);
    field('role', 'Role title', $state['user']['role']);
    field('currentPassword', 'Current password', '', 'password', false);
    field('newPassword', 'New password', '', 'password', false);
    end_form('Save account');
    echo '</section>';
    card('Appearance');
    post_form('theme');
    choice('theme', 'Theme', ['system' => 'System', 'light' => 'Light', 'dark' => 'Dark'], $_SESSION['web_theme'] ?? 'system');
    end_form('Save theme');
    echo '</section>';
    card('Demo data');
    confirm_action('demo.reset', 'Reset demo data', 'This replaces existing workspace records with sample data.');
    echo '</section>';
}

settings_view($state);
