<?php
declare(strict_types=1);

function settings_view(array $state): void
{
    $settings = $state['settings'];
    $isAdmin = is_admin($state['user']);
    ?>
    <div class="settings-layout">
    <?php if (!$isAdmin): ?>
    <section class="card web-card settings-organization">
    <h2>Your details</h2>
    <dl class="dl account-details">
        <dt>Name</dt><dd><?= h($state['user']['name']) ?></dd>
        <dt>Job title</dt><dd><?= h($state['user']['role']) ?></dd>
        <dt>Username</dt><dd><?= h($state['user']['username']) ?></dd>
        <dt>System access</dt><dd>Employee — your own records only<?= !empty($state['user']['hasTeam']) ? ', plus your direct reports’ requests' : '' ?></dd>
    </dl>
    <p class="muted">Your name, position and department come from your HR record. Ask your HR administrator to correct them.</p>
    </section>
    <?php else: ?>
    <section class="card web-card settings-organization">
    <h2>Organization</h2>
    <?php
    post_form('settings.save');
    ?>
    <fieldset class="settings-fields">
        <legend>Workspace</legend>
        <div class="settings-fields-grid">
            <?php
            field('company', 'Company name', $settings['company']);
            field('workspace', 'Workspace name', $settings['workspace']);
            ?>
        </div>
    </fieldset>
    <fieldset class="settings-fields">
        <legend>Payroll</legend>
        <div class="settings-fields-grid">
            <?php
            choice('currency', 'Currency', options(['PHP', 'USD', 'EUR', 'GBP', 'SGD', 'AUD', 'CAD', 'JPY', 'INR']), $settings['currency']);
            field('taxRate', 'Tax rate (%)', $settings['taxRate'], 'number');
            field('benefits', 'Benefits deduction', $settings['benefits'], 'number');
            ?>
        </div>
    </fieldset>
    <fieldset class="settings-fields settings-leave">
        <legend>Leave allowances (days)</legend>
        <div class="settings-fields-grid">
            <?php foreach (LEAVE_TYPES as $type) field('allowances[' . $type . ']', $type, $settings['allowances'][$type] ?? 0, 'number'); ?>
        </div>
    </fieldset>
    <fieldset class="settings-fields">
        <legend>Office hours</legend>
        <div class="settings-fields-grid">
            <?php
            field('workStart', 'Starts', $settings['workStart'] ?? '08:00', 'time');
            field('workEnd', 'Ends', $settings['workEnd'] ?? '17:00', 'time');
            ?>
        </div>
    </fieldset>
    <div class="settings-actions"><button type="submit" class="btn primary">Save organization</button></div>
    </form>
    </section>
    <?php endif; ?>
    <?php
    card('Profile photo');
    ?>
    <div class="profile-photo-settings">
        <div class="profile-photo-preview">
            <span class="avatar lg"><?php if (!empty($state['user']['avatarUrl'])): ?><img src="<?= h($state['user']['avatarUrl']) ?>" alt="Current profile photo"><?php else: ?><?= h(initials($state['user']['name'])) ?><?php endif; ?></span>
            <div><strong><?= h($state['user']['name']) ?></strong><small>Shown beside your name in the header.</small></div>
        </div>
        <?php post_form('profile.photo', [], false, 'multipart/form-data'); ?>
        <div class="field"><label for="profileImage">Choose a profile image</label><input class="input" id="profileImage" name="profileImage" type="file" accept="image/jpeg,image/png,image/webp" aria-describedby="profile-image-hint" required><small class="hint" id="profile-image-hint">JPG, PNG, or WebP · up to 2 MB · max 2048 × 2048 px</small></div>
        <?php end_form('Upload photo'); ?>
        <?php if (!empty($state['user']['avatarUrl'])): ?>
            <?php post_form('profile.photo.remove', [], true); ?>
                <button type="submit" class="btn">Remove photo</button>
            </form>
        <?php endif; ?>
    </div>
    </section>
    <div class="settings-account">
    <?php
    card('Account & security');
    ?>
    <?php if (!empty($state['user']['mustChangePassword'])): ?>
        <p class="temp-password-note">You’re using a temporary password from HR. Choose a new one that only you know.</p>
    <?php endif; ?>
    <div class="toolbar">
        <?php if ($isAdmin): ?>
        <details data-form-dialog="form">
            <summary class="btn">Edit profile</summary>
            <?php
            post_form('profile.save');
            field('name', 'Display name', $state['user']['name']);
            field('role', 'Role title', $state['user']['role']);
            end_form('Save profile');
            ?>
        </details>
        <?php endif; ?>
        <details data-form-dialog="form"<?= !empty($state['user']['mustChangePassword']) ? ' open' : '' ?>>
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
    if ($isAdmin) {
        card('Company updates');
        echo '<p class="muted">Short notices shown to every employee on their Overview, newest first. The next pay date is added automatically while a pay run is in progress.</p>';
        echo '<details class="form-disclosure" data-form-dialog="form"><summary class="btn primary">Post an update</summary>';
        post_form('announcement.save');
        field('title', 'Headline', '', 'text', true, '', ['maxlength' => '120']);
        field('body', 'Message', '', 'text', false, '', ['maxlength' => '500']);
        end_form('Post update');
        echo '</details>';
        web_table(['Update', 'Posted', 'Actions'], array_map(function ($a) {
            return [
                function () use ($a) { echo '<strong>' . h($a['title']) . '</strong>' . ($a['body'] !== '' ? '<span class="sub">' . h($a['body']) . '</span>' : ''); },
                (new DateTimeImmutable($a['createdAt']))->format('M j, Y'),
                function () use ($a) { confirm_action('announcement.delete', 'Remove', 'Remove this update for everyone?', ['id' => $a['id']]); },
            ];
        }, $state['announcements'] ?? []));
        echo '</section>';
        card('Demo data');
        confirm_action('demo.reset', 'Reset demo data', 'This replaces existing workspace records with sample data. Employee sign-in accounts are removed; administrator accounts are kept.');
        echo '</section>';
    }
    echo '</div></div>';
}

settings_view($state);
