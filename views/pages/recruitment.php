<?php
declare(strict_types=1);

function recruitment_view(array $state): void
{
    $positions = array_column($state['positions'], null, 'id');
    $id = (int) ($_GET['id'] ?? array_key_first($positions));
    $position = $positions[$id] ?? [];
    ?>
    <div class="grid cols-side">
        <section class="card">
            <div class="card-head"><h3>Open and closed roles</h3><a class="btn sm" href="<?= h(page_url('recruitment', ['id' => 'new'])) ?>"><?= icon('plus', 14) ?> Add role</a></div>
            <div class="pos-list">
                <?php foreach ($positions as $role): ?>
                    <a class="pos-item<?= $role['id'] === $id ? ' on' : '' ?>" href="<?= h(page_url('recruitment', ['id' => $role['id']])) ?>">
                        <span class="line"><strong><?= h($role['title']) ?></strong><span class="pill"><?= h(ucfirst($role['status'])) ?></span></span>
                        <small><?= h(implode(' · ', array_filter([$role['department'], $role['location']]))) ?></small>
                    </a>
                <?php endforeach; ?>
            </div>
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
    post_form('position.save', $position ? ['id' => $id] : []);
    field('title', 'Role title', $position['title'] ?? '');
    choice('department', 'Department', options(DEPARTMENTS), $position['department'] ?? '');
    choice('type', 'Employment type', options(EMP_TYPES), $position['type'] ?? '');
    field('location', 'Location', $position['location'] ?? '', 'text', false);
    end_form('Save role');
    if ($position) {
        $open = $position['status'] === 'open';
        action_button('position.status', $open ? 'Close role' : 'Reopen role', ['id' => $id, 'status' => $open ? 'closed' : 'open']);
    }
    echo '</section>';
    if (!$position) return;

    $candidates = array_filter($state['candidates'], fn($c) => $c['positionId'] === $id);
    ?>
    <section class="card">
        <div class="card-head"><h3>Candidate pipeline</h3></div>
        <details class="card-body">
            <summary>Add candidate</summary>
            <?php post_form('candidate.save', ['positionId' => $id]);
            field('name', 'Candidate name');
            field('email', 'Email', '', 'email', false);
            field('source', 'Source', '', 'text', false);
            end_form('Add candidate'); ?>
        </details>
        <div class="board">
            <?php foreach (STAGES as $stage):
                $column = array_filter($candidates, fn($c) => $c['stage'] === $stage);
            ?>
                <div class="col">
                    <h4><?= h($stage) ?> <span class="muted"><?= count($column) ?></span></h4>
                    <?php foreach ($column as $candidate) candidate_card($candidate, $id); ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php $rejected = array_filter($candidates, fn($c) => $c['stage'] === 'Rejected'); ?>
        <?php if ($rejected): ?>
            <details class="card-body"><summary>Rejected candidates (<?= count($rejected) ?>)</summary>
                <?php foreach ($rejected as $candidate) candidate_card($candidate, $id); ?>
            </details>
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

function candidate_card(array $candidate, int $positionId): void
{
    ?>
    <article class="cand">
        <strong><?= h($candidate['name']) ?></strong>
        <small><?= h(implode(' · ', array_filter([$candidate['email'], $candidate['source']]))) ?></small>
        <?php post_form('candidate.move', ['id' => $candidate['id']]);
        choice('stage', 'Move to', options([...STAGES, 'Rejected']), $candidate['stage']);
        end_form('Save stage');
        if ($candidate['stage'] === 'Hired' && !$candidate['employeeId']) {
            link_to('recruitment', 'Convert to employee', ['id' => $positionId, 'candidate' => $candidate['id']]);
        } elseif ($candidate['employeeId']) {
            link_to('employees', 'Employee profile', ['id' => $candidate['employeeId']]);
        } ?>
    </article>
    <?php
}

recruitment_view($state);
