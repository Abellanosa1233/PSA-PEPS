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
    card('Account & security');
    ?>
    <div class="card-body toolbar">
        <details data-form-dialog="form">
            <summary class="btn">Edit profile</summary>
            <?php
            post_form('profile.save');
            field('name', 'Display name', $state['user']['name']);
            field('role', 'Role title', $state['user']['role']);
            end_form('Save profile');
            ?>
        </details>
        <details data-form-dialog="form">
            <summary class="btn">Change password</summary>
            <?php post_form('password.change'); ?>
            <?php foreach ([
                ['currentPassword', 'Current password', 'current-password'],
                ['newPassword', 'New password', 'new-password'],
                ['confirmPassword', 'Confirm new password', 'new-password'],
            ] as [$name, $label, $autocomplete]): ?>
                <div class="field full">
                    <label for="account-<?= h($name) ?>"><?= h($label) ?></label>
                    <div class="account-password-input">
                        <input class="input" id="account-<?= h($name) ?>" name="<?= h($name) ?>" type="password"
                            autocomplete="<?= h($autocomplete) ?>" required<?= $name === 'newPassword' ? ' minlength="8"' : '' ?>>
                        <button class="btn" type="button" data-password-toggle hidden>Show</button>
                    </div>
                    <?php if ($name === 'newPassword'): ?><small class="hint">Use at least 8 characters.</small><?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php end_form('Change password'); ?>
        </details>
    </div>
    </section>
    <?php
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
