<?php
require_once __DIR__ . '/../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('admin');

require_once __DIR__ . '/../includes/db.php';

$active_page        = 'feedback';
$page_title          = 'Guest Feedback';
$breadcrumb          = 'Feedback';
$page_action_label   = null;
$show_period_toggle  = false;

$pdo  = bienmarko_db();
$csrf = bienmarko_csrf_token();

/* ------------------------------------------------------------------
   Ensure feedback table (and its moderation columns) exist — safe on
   every request, a no-op once they're already there.
------------------------------------------------------------------ */
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS `feedback` (
        `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `guest_id`     INT UNSIGNED NOT NULL,
        `rating`       TINYINT UNSIGNED NOT NULL,
        `category`     ENUM('general','room','service','cleanliness','other') NOT NULL DEFAULT 'general',
        `message`      TEXT NOT NULL,
        `is_read`      TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
        `is_published` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
        `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_guest`     (`guest_id`),
        KEY `idx_created`   (`created_at`),
        KEY `idx_read`      (`is_read`),
        KEY `idx_published` (`is_published`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$pdo->exec("ALTER TABLE `feedback` ADD COLUMN IF NOT EXISTS `is_published` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER `is_read`");

/* ------------------------------------------------------------------
   Stat cards
------------------------------------------------------------------ */
$statTotal     = (int) $pdo->query('SELECT COUNT(*) FROM feedback')->fetchColumn();
$statAvgRaw    = $pdo->query('SELECT AVG(rating) FROM feedback')->fetchColumn();
$statAvg       = $statAvgRaw !== null ? number_format((float) $statAvgRaw, 1) : '--';
$statUnread    = (int) $pdo->query('SELECT COUNT(*) FROM feedback WHERE is_read = 0')->fetchColumn();
$statPublished = (int) $pdo->query('SELECT COUNT(*) FROM feedback WHERE is_published = 1')->fetchColumn();

/* ------------------------------------------------------------------
   Filters (GET)
------------------------------------------------------------------ */
$allowedCategories = ['general', 'room', 'service', 'cleanliness', 'other'];

$filterRating = filter_var($_GET['rating'] ?? '', FILTER_VALIDATE_INT);
if ($filterRating === false || $filterRating < 1 || $filterRating > 5) {
    $filterRating = 0;
}

$filterCategory = (string) ($_GET['category'] ?? '');
if (!in_array($filterCategory, $allowedCategories, true)) {
    $filterCategory = '';
}

$filterStatus = (string) ($_GET['status'] ?? '');
if (!in_array($filterStatus, ['', 'unread', 'read'], true)) {
    $filterStatus = '';
}

$filterPublished = (string) ($_GET['published'] ?? '');
if (!in_array($filterPublished, ['', 'yes', 'no'], true)) {
    $filterPublished = '';
}

$filterSearch = trim((string) ($_GET['search'] ?? ''));

$perPage     = 10;
$currentPage = max(1, (int) ($_GET['page'] ?? 1));

/* ------------------------------------------------------------------
   Build + run query
------------------------------------------------------------------ */
$whereClauses = [];
$params       = [];

if ($filterRating > 0) {
    $whereClauses[] = 'f.rating = :rating';
    $params['rating'] = $filterRating;
}
if ($filterCategory !== '') {
    $whereClauses[] = 'f.category = :category';
    $params['category'] = $filterCategory;
}
if ($filterStatus === 'unread') {
    $whereClauses[] = 'f.is_read = 0';
} elseif ($filterStatus === 'read') {
    $whereClauses[] = 'f.is_read = 1';
}
if ($filterPublished === 'yes') {
    $whereClauses[] = 'f.is_published = 1';
} elseif ($filterPublished === 'no') {
    $whereClauses[] = 'f.is_published = 0';
}
if ($filterSearch !== '') {
    $whereClauses[] = '(g.first_name LIKE :s1 OR g.last_name LIKE :s2 OR g.email LIKE :s3 OR f.message LIKE :s4)';
    $like = '%' . $filterSearch . '%';
    foreach (['s1', 's2', 's3', 's4'] as $key) {
        $params[$key] = $like;
    }
}

$where = $whereClauses ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM feedback f LEFT JOIN guests g ON g.id = f.guest_id {$where}");
$countStmt->execute($params);
$totalFeedback = (int) $countStmt->fetchColumn();

$totalPages  = max(1, (int) ceil($totalFeedback / $perPage));
$currentPage = min($currentPage, $totalPages);
$offset      = ($currentPage - 1) * $perPage;

$stmt = $pdo->prepare(
    "SELECT f.id, f.rating, f.category, f.message, f.is_read, f.is_published, f.created_at,
            g.first_name, g.last_name, g.email
     FROM feedback f
     LEFT JOIN guests g ON g.id = f.guest_id
     {$where}
     ORDER BY f.created_at DESC
     LIMIT {$perPage} OFFSET {$offset}"
);
$stmt->execute($params);
$feedbackRows = $stmt->fetchAll();

/* ------------------------------------------------------------------
   Helpers
------------------------------------------------------------------ */
function fb_admin_name(array $row): string
{
    $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
    return $name !== '' ? $name : 'Deleted account';
}

function fb_admin_category_label(string $category): string
{
    $map = [
        'general'     => 'General',
        'room'        => 'Room & Amenities',
        'service'     => 'Staff & Service',
        'cleanliness' => 'Cleanliness',
        'other'       => 'Other',
    ];
    return $map[$category] ?? 'General';
}

function fb_admin_stars(int $rating): string
{
    $html = '<span class="fb-stars-admin" aria-label="' . $rating . ' out of 5">';
    for ($i = 1; $i <= 5; $i++) {
        $cls  = $i <= $rating ? 'icon-sm fb-star-admin is-filled' : 'icon-sm fb-star-admin';
        $html .= icon('star', $cls);
    }
    return $html . '</span>';
}

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/topbar.php';
require_once __DIR__ . '/includes/ui.php';
?>

<style>
/* ============================================================
   admin/feedback.php — page-scoped styles
   ============================================================ */
.toolbar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 18px; }
.toolbar-search { position: relative; flex: 1; min-width: 220px; }
.toolbar-search input {
    width: 100%; padding: 9px 14px 9px 38px;
    background: var(--panel); border: 1px solid var(--border);
    border-radius: var(--r-md); color: var(--text); font-size: 14px;
    transition: border-color var(--transition);
}
.toolbar-search input:focus { outline: none; border-color: var(--gold-500); }
.toolbar-search input::placeholder { color: var(--text-tertiary); }
.toolbar-search-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-tertiary); pointer-events: none; }
.filter-select {
    padding: 9px 14px; background: var(--panel); border: 1px solid var(--border);
    border-radius: var(--r-md); color: var(--text); font-size: 14px;
    cursor: pointer; transition: border-color var(--transition);
}
.filter-select:focus { outline: none; border-color: var(--gold-500); }

.table-head  { display: flex; align-items: center; justify-content: space-between; padding: 18px 20px 0; margin-bottom: 14px; }
.panel-title { font-family: var(--font-display); font-size: 15px; font-weight: 700; color: var(--text); }
.results-count { font-size: 12.5px; color: var(--text-secondary); }
.table-scroll { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; }
thead th { padding: 10px 14px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--text-tertiary); border-bottom: 1px solid var(--border); text-align: left; white-space: nowrap; }
tbody tr { border-bottom: 1px solid var(--border); transition: background .12s; }
tbody tr:last-child { border-bottom: none; }
tbody tr:hover { background: var(--panel-alt); }
tbody td { padding: 12px 14px; font-size: 13.5px; color: var(--text); vertical-align: top; }
.td-guest  { font-weight: 600; white-space: nowrap; }
.td-sub    { font-size: 11.5px; color: var(--text-secondary); margin-top: 1px; }
.td-message { max-width: 360px; white-space: normal; line-height: 1.45; }
.td-actions { display: flex; gap: 6px; white-space: nowrap; }

.fb-stars-admin { display: inline-flex; gap: 2px; }
.fb-star-admin { opacity: 0.3; }
.fb-star-admin.is-filled { opacity: 1; color: var(--gold-400); }

.category-badge {
    display: inline-block; font-size: 11px; font-weight: 600;
    padding: 3px 9px; border-radius: var(--r-full);
    background: var(--panel-alt); color: var(--text-secondary); white-space: nowrap;
}

.ab { display: inline-flex; align-items: center; padding: 3px 9px; border-radius: var(--r-full); font-size: 11px; font-weight: 700; letter-spacing: .03em; }
.ab--red  { background: var(--red-bg);  color: var(--red); }
.ab--grey { background: var(--panel-alt); color: var(--text-secondary); }
.ab--gold { background: var(--gold-tint-bg); color: var(--gold-400); }
.status-stack { display: flex; flex-direction: column; align-items: flex-start; gap: 5px; }

.btn-sm { display: inline-flex; align-items: center; gap: 4px; padding: 5px 10px; border-radius: var(--r-sm); font-size: 12px; font-weight: 600; border: 1px solid var(--border); background: var(--panel-alt); color: var(--text-secondary); cursor: pointer; transition: all var(--transition); }
.btn-sm:hover { color: var(--text); border-color: var(--border-strong); }
.btn-sm--success { background: var(--emerald-bg); border-color: var(--emerald); color: var(--emerald); }
.btn-sm--success:hover { background: var(--emerald); color: #fff; }
.btn-sm--danger { background: var(--red-bg); border-color: var(--red); color: var(--red); }
.btn-sm--danger:hover { background: var(--red); color: #fff; }
.btn-sm--gold { background: var(--gold-tint-bg); border-color: var(--gold-600); color: var(--gold-400); }
.btn-sm--gold:hover { background: var(--gold-600); color: var(--on-accent); }
.btn-sm[disabled] { opacity: 0.5; cursor: default; pointer-events: none; }

.toast-wrap { position: fixed; bottom: 24px; right: 24px; display: flex; flex-direction: column; gap: 10px; z-index: 999; }
.toast {
    padding: 12px 16px; border-radius: var(--r-md); font-size: 13.5px; font-weight: 600;
    box-shadow: 0 10px 30px -12px rgba(0,0,0,.5);
    animation: toast-in .2s ease;
}
.toast--success { background: var(--emerald-bg); border: 1px solid var(--emerald); color: var(--emerald); }
.toast--error   { background: var(--red-bg);     border: 1px solid var(--red);     color: var(--red); }
@keyframes toast-in { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
</style>

<div class="stack">
    <div class="stat-grid">
        <?php
            render_stat_card('Total Feedback', (string) $statTotal, 'message', 'blue');
            render_stat_card('Average Rating', $statAvg, 'star', 'amber');
            render_stat_card('Unread', (string) $statUnread, 'mail', 'red');
            render_stat_card('Published on Site', (string) $statPublished, 'check', 'emerald');
        ?>
    </div>

    <div class="panel">
        <div class="table-head">
            <span class="panel-title">All Feedback</span>
            <span class="results-count"><?php echo number_format($totalFeedback); ?> total</span>
        </div>

        <div style="padding: 0 20px;">
            <form class="toolbar" method="get" id="feedbackFilterForm">
                <div class="toolbar-search">
                    <span class="toolbar-search-icon"><?php echo icon('search', 'icon-sm'); ?></span>
                    <input type="text" name="search" placeholder="Search guest name, email, or message..." value="<?php echo htmlspecialchars($filterSearch); ?>">
                </div>
                <select class="filter-select" name="rating" onchange="this.form.submit()">
                    <option value="">All Ratings</option>
                    <?php for ($r = 5; $r >= 1; $r--): ?>
                        <option value="<?php echo $r; ?>" <?php echo $filterRating === $r ? 'selected' : ''; ?>><?php echo $r; ?> Star<?php echo $r === 1 ? '' : 's'; ?></option>
                    <?php endfor; ?>
                </select>
                <select class="filter-select" name="category" onchange="this.form.submit()">
                    <option value="">All Categories</option>
                    <?php foreach ($allowedCategories as $cat): ?>
                        <option value="<?php echo $cat; ?>" <?php echo $filterCategory === $cat ? 'selected' : ''; ?>><?php echo htmlspecialchars(fb_admin_category_label($cat)); ?></option>
                    <?php endforeach; ?>
                </select>
                <select class="filter-select" name="status" onchange="this.form.submit()">
                    <option value="">All Status</option>
                    <option value="unread" <?php echo $filterStatus === 'unread' ? 'selected' : ''; ?>>Unread</option>
                    <option value="read" <?php echo $filterStatus === 'read' ? 'selected' : ''; ?>>Read</option>
                </select>
                <select class="filter-select" name="published" onchange="this.form.submit()">
                    <option value="">Site: All</option>
                    <option value="yes" <?php echo $filterPublished === 'yes' ? 'selected' : ''; ?>>Published</option>
                    <option value="no" <?php echo $filterPublished === 'no' ? 'selected' : ''; ?>>Not Published</option>
                </select>
                <?php if ($statUnread > 0): ?>
                    <button type="button" class="btn-sm" id="markAllReadBtn"><?php echo icon('check', 'icon-sm'); ?> Mark All Read</button>
                <?php endif; ?>
            </form>
        </div>

        <?php if (empty($feedbackRows)): ?>
            <?php render_table_empty(
                ['Guest', 'Rating', 'Category', 'Message', 'Date', 'Status', 'Actions'],
                'message',
                'No feedback found',
                $totalFeedback === 0
                    ? 'Guest reviews and feedback will be collected here once submitted.'
                    : 'No feedback matches your current filters.'
            ); ?>
        <?php else: ?>
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Guest</th>
                            <th>Rating</th>
                            <th>Category</th>
                            <th>Message</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="feedbackTableBody">
                        <?php foreach ($feedbackRows as $row): ?>
                            <tr id="fb-row-<?php echo (int) $row['id']; ?>">
                                <td class="td-guest">
                                    <?php echo htmlspecialchars(fb_admin_name($row)); ?>
                                    <div class="td-sub"><?php echo htmlspecialchars($row['email'] ?? ''); ?></div>
                                </td>
                                <td><?php echo fb_admin_stars((int) $row['rating']); ?></td>
                                <td><span class="category-badge"><?php echo htmlspecialchars(fb_admin_category_label($row['category'])); ?></span></td>
                                <td class="td-message"><?php echo htmlspecialchars($row['message']); ?></td>
                                <td><?php echo date('M j, Y', strtotime($row['created_at'])); ?></td>
                                <td>
                                    <div class="status-stack">
                                        <span class="ab <?php echo $row['is_read'] ? 'ab--grey' : 'ab--red'; ?>" id="fb-status-<?php echo (int) $row['id']; ?>">
                                            <?php echo $row['is_read'] ? 'Read' : 'Unread'; ?>
                                        </span>
                                        <span class="ab <?php echo $row['is_published'] ? 'ab--gold' : 'ab--grey'; ?>" id="fb-published-<?php echo (int) $row['id']; ?>">
                                            <?php echo $row['is_published'] ? 'On Site' : 'Hidden'; ?>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <div class="td-actions">
                                        <button
                                            type="button"
                                            class="btn-sm btn-sm--success fb-mark-read-btn"
                                            data-id="<?php echo (int) $row['id']; ?>"
                                            <?php echo $row['is_read'] ? 'disabled' : ''; ?>
                                        >
                                            <?php echo icon('check', 'icon-sm'); ?> Mark Read
                                        </button>
                                        <button
                                            type="button"
                                            class="btn-sm btn-sm--gold fb-publish-btn"
                                            data-id="<?php echo (int) $row['id']; ?>"
                                            data-published="<?php echo $row['is_published'] ? '1' : '0'; ?>"
                                            id="fb-publish-btn-<?php echo (int) $row['id']; ?>"
                                        >
                                            <?php echo icon($row['is_published'] ? 'close' : 'check', 'icon-sm'); ?>
                                            <?php echo $row['is_published'] ? 'Unpublish' : 'Publish'; ?>
                                        </button>
                                        <button type="button" class="btn-sm btn-sm--danger fb-delete-btn" data-id="<?php echo (int) $row['id']; ?>">
                                            <?php echo icon('trash', 'icon-sm'); ?> Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php render_pagination(
                'feedback.php',
                $currentPage,
                $perPage,
                $totalFeedback,
                array_filter([
                    'rating'    => $filterRating ?: null,
                    'category'  => $filterCategory ?: null,
                    'status'    => $filterStatus ?: null,
                    'published' => $filterPublished ?: null,
                    'search'    => $filterSearch ?: null,
                ]),
                'entry'
            ); ?>
        <?php endif; ?>
    </div>
</div>

<div class="toast-wrap" id="toastWrap"></div>

<script>
var CSRF = <?php echo json_encode($csrf); ?>;

// Mirrors admin/includes/icons.php's icon('check'|'close', 'icon-sm') output,
// so the Publish/Unpublish button's icon can be swapped after a successful
// toggle without a full page reload.
var ICON_SVG = {
    check: '<svg class="icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><polyline points="4 12.5 9.5 18 20 6"></polyline></svg>',
    close: '<svg class="icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><line x1="6" y1="6" x2="18" y2="18"></line><line x1="18" y1="6" x2="6" y2="18"></line></svg>'
};

function toast(message, type) {
    var wrap = document.getElementById('toastWrap');
    if (!wrap) return;
    var el = document.createElement('div');
    el.className = 'toast toast--' + (type || 'success');
    el.textContent = message;
    wrap.appendChild(el);
    window.setTimeout(function () {
        el.remove();
    }, 3200);
}

function feedbackApi(payload) {
    return fetch('api/feedback.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(Object.assign({ csrf_token: CSRF }, payload))
    }).then(function (res) {
        return res.json().then(function (data) { return { ok: res.ok, data: data }; });
    });
}

document.addEventListener('click', function (e) {
    var markBtn = e.target.closest('.fb-mark-read-btn');
    if (markBtn) {
        var id = markBtn.getAttribute('data-id');
        markBtn.disabled = true;
        feedbackApi({ action: 'mark_read', id: id })
            .then(function (result) {
                if (!result.ok || !result.data.success) {
                    throw new Error((result.data && result.data.error) || 'Could not update this feedback.');
                }
                var badge = document.getElementById('fb-status-' + id);
                if (badge) {
                    badge.textContent = 'Read';
                    badge.className = 'ab ab--grey';
                }
                toast('Marked as read.', 'success');
            })
            .catch(function (err) {
                markBtn.disabled = false;
                toast(err.message, 'error');
            });
        return;
    }

    var delBtn = e.target.closest('.fb-delete-btn');
    if (delBtn) {
        if (!window.confirm('Delete this feedback? This cannot be undone.')) return;
        var delId = delBtn.getAttribute('data-id');
        delBtn.disabled = true;
        feedbackApi({ action: 'delete', id: delId })
            .then(function (result) {
                if (!result.ok || !result.data.success) {
                    throw new Error((result.data && result.data.error) || 'Could not delete this feedback.');
                }
                var row = document.getElementById('fb-row-' + delId);
                if (row) row.remove();
                toast('Feedback deleted.', 'success');
            })
            .catch(function (err) {
                delBtn.disabled = false;
                toast(err.message, 'error');
            });
        return;
    }

    var pubBtn = e.target.closest('.fb-publish-btn');
    if (pubBtn) {
        var pubId = pubBtn.getAttribute('data-id');
        var willPublish = pubBtn.getAttribute('data-published') !== '1';
        pubBtn.disabled = true;
        feedbackApi({ action: 'toggle_publish', id: pubId, publish: willPublish })
            .then(function (result) {
                if (!result.ok || !result.data.success) {
                    throw new Error((result.data && result.data.error) || 'Could not update this feedback.');
                }
                var badge = document.getElementById('fb-published-' + pubId);
                if (badge) {
                    badge.textContent = willPublish ? 'On Site' : 'Hidden';
                    badge.className = 'ab ' + (willPublish ? 'ab--gold' : 'ab--grey');
                }
                pubBtn.setAttribute('data-published', willPublish ? '1' : '0');
                pubBtn.innerHTML = ICON_SVG[willPublish ? 'close' : 'check'] + (willPublish ? ' Unpublish' : ' Publish');
                toast(willPublish ? 'Now showing on the landing page.' : 'Hidden from the landing page.', 'success');
            })
            .catch(function (err) {
                toast(err.message, 'error');
            })
            .finally(function () {
                pubBtn.disabled = false;
            });
        return;
    }

    var markAllBtn = e.target.closest('#markAllReadBtn');
    if (markAllBtn) {
        markAllBtn.disabled = true;
        feedbackApi({ action: 'mark_all_read' })
            .then(function (result) {
                if (!result.ok || !result.data.success) {
                    throw new Error((result.data && result.data.error) || 'Could not update feedback.');
                }
                toast('All feedback marked as read.', 'success');
                window.setTimeout(function () { window.location.reload(); }, 500);
            })
            .catch(function (err) {
                markAllBtn.disabled = false;
                toast(err.message, 'error');
            });
    }
});
</script>

<?php require __DIR__ . '/includes/foot.php'; ?>
