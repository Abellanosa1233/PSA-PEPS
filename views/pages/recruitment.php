<?php
declare(strict_types=1);

function recruitment_view(array $state): void
{
    $positions = array_column($state['positions'], null, 'id');
    $id = (int) ($_GET['id'] ?? (reset($positions)['id'] ?? 0));
    $position = $positions[$id] ?? [];
    $roles = paginate($positions, 'rolesPage');
    ?>
    <div class="grid cols-side">
        <section class="card">
            <div class="card-head"><h3>Open and closed roles</h3><a class="btn sm" href="<?= h(page_url('recruitment', ['id' => 'new'])) ?>"><?= icon('plus', 14) ?> Add role</a></div>
            <div class="pos-list">
                <?php foreach ($roles['rows'] as $role): ?>
                    <a class="pos-item<?= $role['id'] === $id ? ' on' : '' ?>" href="<?= h(page_url('recruitment', ['id' => $role['id'], 'rolesPage' => $roles['current']])) ?>">
                        <span class="line"><strong><?= h($role['title']) ?></strong><span class="pill"><?= h(ucfirst($role['status'])) ?></span></span>
                        <small><?= h(implode(' · ', array_filter([$role['department'], $role['location']]))) ?></small>
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
    card($position['title'] ?? 'New role');
    echo '<details class="form-disclosure" data-form-dialog="form"' . ($position ? '' : ' open data-auto-open') . '><summary class="btn">'
        . ($position ? icon('edit', 14) . ' Edit role' : 'Add role') . '</summary>';
    post_form('position.save', $position ? ['id' => $id] : []);
    field('title', 'Role title', $position['title'] ?? '');
    choice('department', 'Department', options(DEPARTMENTS), $position['department'] ?? '');
    choice('type', 'Employment type', options(EMP_TYPES), $position['type'] ?? '');
    field('location', 'Location', $position['location'] ?? '', 'text', false);
    end_form('Save role');
    echo '</details>';
    if ($position) {
        $open = $position['status'] === 'open';
        action_button('position.status', $open ? 'Close role' : 'Reopen role', ['id' => $id, 'status' => $open ? 'closed' : 'open']);
    }
    echo '</section>';
    if (!$position) return;

    $candidates = array_filter($state['candidates'], function ($c) use ($id) { return $c['positionId'] === $id; });
    $listMode = ($_GET['candidates'] ?? '') === 'all';
    $roleQuery = ['id' => $id] + array_intersect_key($_GET, ['rolesPage' => true]);
    ?>
    <section class="card">
        <div class="card-head">
            <h3><?= $listMode ? 'All candidates' : 'Candidate pipeline' ?></h3>
            <?php link_to('recruitment', $listMode ? 'View pipeline' : 'View all candidates', $roleQuery + ($listMode ? [] : ['candidates' => 'all']), 'link-btn'); ?>
        </div>
        <details class="card-body form-disclosure" data-form-dialog="form">
            <summary class="btn">Add candidate</summary>
            <?php post_form('candidate.save', ['positionId' => $id]);
            field('name', 'Candidate name');
            field('email', 'Email', '', 'email', false);
            field('source', 'Source', '', 'text', false);
            end_form('Add candidate'); ?>
        </details>
        <?php if ($listMode): ?>
            <?php candidate_list($candidates, $roleQuery); ?>
        <?php else: ?>
        <div class="board">
            <?php foreach (STAGES as $stage):
                $column = array_filter($candidates, function ($c) use ($stage) { return $c['stage'] === $stage; });
            ?>
                <div class="col">
                    <h4><?= h($stage) ?> <span class="muted"><?= count($column) ?></span></h4>
                    <?php foreach (array_slice($column, 0, 5) as $candidate) candidate_card($candidate, $id); ?>
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
