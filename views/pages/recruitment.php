<?php
declare(strict_types=1);

function recruitment_view(array $state): void
{
    $positions = array_column($state['positions'], null, 'id');
    $id = (int) ($_GET['id'] ?? (reset($positions)['id'] ?? 0));
    $position = $positions[$id] ?? [];
    $roleCandidateCounts = [];
    foreach ($state['candidates'] as $candidate) {
        $roleCandidateCounts[$candidate['positionId']] = ($roleCandidateCounts[$candidate['positionId']] ?? 0) + 1;
    }
    $roles = paginate($positions, 'rolesPage');
    ?>
    <div class="grid cols-side recruitment-layout">
        <section class="card recruitment-roles">
            <div class="card-head recruitment-roles-head"><div><h3>Roles</h3><p><?= count($positions) ?> positions</p></div><a class="btn sm" href="<?= h(page_url('recruitment', ['id' => 'new'])) ?>"><?= icon('plus', 14) ?> Add role</a></div>
            <div class="pos-list">
                <?php foreach ($roles['rows'] as $role): ?>
                    <?php $roleCandidates = $roleCandidateCounts[$role['id']] ?? 0; ?>
                    <a class="pos-item<?= $role['id'] === $id ? ' on' : '' ?>" href="<?= h(page_url('recruitment', ['id' => $role['id'], 'rolesPage' => $roles['current']])) ?>">
                        <span class="pos-item-heading"><strong><?= h($role['title']) ?></strong><span class="recruitment-status <?= $role['status'] === 'open' ? 'is-open' : 'is-closed' ?>"><?= h(ucfirst($role['status'])) ?></span></span>
                        <small class="pos-item-meta"><?= h(implode(' · ', array_filter([$role['department'], $role['location']]))) ?></small>
                        <span class="pos-item-count"><strong><?= $roleCandidates ?></strong> <?= $roleCandidates === 1 ? 'candidate' : 'candidates' ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php pagination($roles, 'recruitment', array_intersect_key($_GET, ['id' => true, 'candidates' => true, 'q' => true, 'p' => true]), 'roles', 'rolesPage'); ?>
        </section>
        <div class="web-stack">
            <?php recruitment_pipeline($state, $position, $id); ?>
        </div>
    </div>
    <?php
}

function recruitment_pipeline(array $state, array $position, int $id): void
{
    if (!$position && ($_GET['id'] ?? '') !== 'new') {
        echo '<section class="card empty"><strong>No role selected</strong></section>';
        return;
    }
    $candidates = $position ? array_values(array_filter($state['candidates'], function ($c) use ($id) { return $c['positionId'] === $id; })) : [];
    $stageCounts = array_fill_keys(array_merge(STAGES, ['Rejected']), 0);
    foreach ($candidates as $candidate) {
        if (isset($stageCounts[$candidate['stage']])) $stageCounts[$candidate['stage']]++;
    }
    $activeCandidates = count($candidates) - ($stageCounts['Hired'] ?? 0) - ($stageCounts['Rejected'] ?? 0);
    ?>
    <section class="card web-card recruitment-role-summary">
        <div class="recruitment-role-heading">
            <div class="recruitment-role-title">
                <span class="recruitment-eyebrow"><?= $position ? 'Selected position' : 'New position' ?></span>
                <h2><?= h($position['title'] ?? 'Create a recruitment role') ?></h2>
                <?php if ($position): ?><p><?= h(implode(' · ', array_filter([$position['department'], $position['type'], $position['location']]))) ?></p><?php endif; ?>
            </div>
            <div class="recruitment-role-actions">
                <details class="form-disclosure" data-form-dialog="form"<?= $position ? '' : ' open data-auto-open' ?>>
                    <summary class="btn"><?= $position ? icon('edit', 14) . ' Edit role' : 'Add role' ?></summary>
                    <?php post_form('position.save', $position ? ['id' => $id] : []);
                    field('title', 'Role title', $position['title'] ?? '');
                    choice('department', 'Department', options(DEPARTMENTS), $position['department'] ?? '');
                    choice('type', 'Employment type', options(EMP_TYPES), $position['type'] ?? '');
                    field('location', 'Location', $position['location'] ?? '', 'text', false);
                    end_form('Save role'); ?>
                </details>
                <?php if ($position):
                    $open = $position['status'] === 'open';
                    action_button('position.status', $open ? 'Close role' : 'Reopen role', ['id' => $id, 'status' => $open ? 'closed' : 'open']);
                endif; ?>
            </div>
        </div>
        <?php if ($position): ?>
            <div class="recruitment-role-metrics" aria-label="Candidate totals">
                <div><span>Total candidates</span><strong><?= count($candidates) ?></strong></div>
                <div><span>Active pipeline</span><strong><?= $activeCandidates ?></strong></div>
                <div><span>Hired</span><strong><?= $stageCounts['Hired'] ?? 0 ?></strong></div>
                <div><span>Rejected</span><strong><?= $stageCounts['Rejected'] ?? 0 ?></strong></div>
            </div>
        <?php endif; ?>
    </section>
    <?php
    if (!$position) return;

    $listMode = ($_GET['candidates'] ?? '') === 'all';
    $roleQuery = ['id' => $id] + array_intersect_key($_GET, ['rolesPage' => true]);
    ?>
    <section class="card recruitment-pipeline">
        <div class="card-head recruitment-pipeline-head">
            <div><h3><?= $listMode ? 'All candidates' : 'Candidate pipeline' ?></h3><p><?= count($candidates) ?> candidates for this position</p></div>
            <div class="recruitment-pipeline-actions">
                <?php link_to('recruitment', $listMode ? 'View pipeline' : 'View all candidates', $roleQuery + ($listMode ? [] : ['candidates' => 'all']), 'btn ghost'); ?>
                <details class="form-disclosure" data-form-dialog="form">
                    <summary class="btn primary">Add candidate</summary>
                    <?php post_form('candidate.save', ['positionId' => $id]);
                    field('name', 'Candidate name');
                    field('email', 'Email', '', 'email', false);
                    field('source', 'Source', '', 'text', false);
                    end_form('Add candidate'); ?>
                </details>
            </div>
        </div>
        <?php if ($listMode): ?>
            <?php candidate_list($candidates, $roleQuery); ?>
        <?php else: ?>
        <div class="board">
            <?php foreach (STAGES as $stage):
                $column = array_filter($candidates, function ($c) use ($stage) { return $c['stage'] === $stage; });
            ?>
                <div class="col">
                    <h4><span><?= h($stage) ?></span><span class="stage-count"><?= count($column) ?></span></h4>
                    <?php foreach (array_slice($column, 0, 5) as $candidate) candidate_card($candidate, $id); ?>
                    <?php if (!$column): ?><p class="stage-empty">No candidates yet</p><?php endif; ?>
                    <?php if (count($column) > 5): ?><small class="muted"><?= count($column) - 5 ?> more in candidate list</small><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php $rejected = array_filter($candidates, function ($c) { return $c['stage'] === 'Rejected'; }); ?>
        <?php if ($rejected): ?>
            <details class="card-body"><summary>Rejected candidates (<?= count($rejected) ?>)</summary>
                <?php foreach (array_slice($rejected, 0, 5) as $candidate) candidate_card($candidate, $id); ?>
            </details>
        <?php endif; ?>
        <?php endif; ?>
    </section>
    <?php
    foreach ($candidates as $candidate) {
        if ($candidate['id'] === (int) ($_GET['candidate'] ?? 0) && $candidate['stage'] === 'Hired' && !$candidate['employeeId']) {
            employee_form($state, [], $candidate + ['position' => $position]);
            break;
        }
    }
}

function candidate_list(array $candidates, array $roleQuery): void
{
    $query = $roleQuery + ['candidates' => 'all'];
    $search = trim((string) ($_GET['q'] ?? ''));
    $matches = array_filter($candidates, function ($c) use ($search) { return $search === '' || mb_stripos($c['name'] . ' ' . $c['email'], $search) !== false; });
    $list = paginate($matches);
    echo '<form method="get" class="card-body toolbar">';
    hidden_inputs(['page' => 'recruitment'] + $query);
    field('q', 'Find a candidate', $search, 'search', false);
    echo '<button class="btn" type="submit">Filter</button>';
    link_to('recruitment', 'Clear filters', $query, 'btn ghost');
    echo '<span class="muted web-push">' . count($matches) . ' matches</span></form>';
    if (!$matches) {
        echo '<div class="empty"><strong>No matching candidates</strong>';
        link_to('recruitment', 'Clear filters', $query, 'btn sm');
        echo '</div>';
        return;
    }
    web_table(['Candidate', 'Email', 'Stage', 'Actions'], array_map(function ($candidate) use ($roleQuery) { return [$candidate['name'], $candidate['email'], $candidate['stage'], function () use ($candidate, $roleQuery) { return candidate_actions($candidate, $roleQuery['id']); }]; },
        $list['rows']));
    pagination($list, 'recruitment', $query + ['q' => $search], 'candidates');
}

function candidate_card(array $candidate, int $positionId): void
{
    ?>
    <article class="cand">
        <strong><?= h($candidate['name']) ?></strong>
        <small><?= h(implode(' · ', array_filter([$candidate['email'], $candidate['source']]))) ?></small>
        <?php candidate_actions($candidate, $positionId); ?>
    </article>
    <?php
}

function candidate_actions(array $candidate, int $positionId): void
{
    post_form('candidate.move', ['id' => $candidate['id']]);
    choice('stage', 'Move to', options(array_merge(STAGES, ['Rejected'])), $candidate['stage']);
    end_form('Save stage');
    if ($candidate['stage'] === 'Hired' && !$candidate['employeeId']) {
        link_to('recruitment', 'Convert to employee', ['id' => $positionId, 'candidate' => $candidate['id']]
            + array_intersect_key($_GET, ['candidates' => true, 'p' => true, 'q' => true, 'rolesPage' => true]));
    } elseif ($candidate['employeeId']) {
        link_to('employees', 'Employee profile', ['id' => $candidate['employeeId']]);
    }
}

recruitment_view($state);
