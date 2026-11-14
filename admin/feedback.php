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
/* Columns the guest side fills in (which stay / accommodation the feedback is about). */
$pdo->exec("ALTER TABLE `feedback` ADD COLUMN IF NOT EXISTS `booking_id` INT UNSIGNED NULL DEFAULT NULL AFTER `guest_id`");
$pdo->exec("ALTER TABLE `feedback` ADD COLUMN IF NOT EXISTS `accommodation_id` INT UNSIGNED NULL DEFAULT NULL AFTER `booking_id`");
$pdo->exec("ALTER TABLE `feedback` ADD COLUMN IF NOT EXISTS `accommodation_type` VARCHAR(100) NULL DEFAULT NULL AFTER `accommodation_id`");
$pdo->exec("ALTER TABLE `feedback` ADD COLUMN IF NOT EXISTS `accommodation_name` VARCHAR(150) NULL DEFAULT NULL AFTER `accommodation_type`");

/* Older feedback has no saved room name, so we look it up through its booking.
   Only do that join if those tables exist. */
$canJoinStay = true;
try {
    $pdo->query('SELECT 1 FROM bookings LIMIT 1');
    $pdo->query('SELECT 1 FROM accommodations LIMIT 1');
} catch (Throwable $e) {
    $canJoinStay = false;
}

/* ------------------------------------------------------------------
   Stat cards
------------------------------------------------------------------ */
$statTotal     = (int) $pdo->query('SELECT COUNT(*) FROM feedback')->fetchColumn();
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

$stayCols  = $canJoinStay
    ? "COALESCE(NULLIF(f.accommodation_name, ''), a.name) AS stay_name, COALESCE(NULLIF(f.accommodation_type, ''), a.type) AS stay_type"
    : 'f.accommodation_name AS stay_name, f.accommodation_type AS stay_type';
$stayJoins = $canJoinStay
    ? 'LEFT JOIN bookings bk ON bk.id = f.booking_id
       LEFT JOIN accommodations a ON a.id = COALESCE(f.accommodation_id, bk.accommodation_id)'
    : '';

$stmt = $pdo->prepare(
    "SELECT f.id, f.rating, f.category, f.message, f.is_published, f.created_at,
            g.first_name, g.last_name, g.email,
            {$stayCols}
     FROM feedback f
     LEFT JOIN guests g ON g.id = f.guest_id
     {$stayJoins}
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

function fb_admin_stay_label(array $row): string
{
    $name = trim((string) ($row['stay_name'] ?? ''));
    return $name !== '' ? $name : '—';
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
.td-stay   { font-weight: 600; white-space: nowrap; }
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

.icon-btn-sm {
    width: 30px; height: 30px; display: inline-flex; align-items: center; justify-content: center;
    border-radius: var(--r-sm); border: 1px solid var(--border); background: var(--panel-alt);
    cursor: pointer; transition: background var(--transition), color var(--transition), border-color var(--transition);
}
.icon-btn-sm--view { color: var(--gold-400); }
.icon-btn-sm--view:hover { background: var(--amber-bg); border-color: var(--gold-600); }

/* View / moderate modal */
.modal-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,.6);
    display: flex; align-items: center; justify-content: center;
    padding: 20px; z-index: 500;
    opacity: 0; pointer-events: none; transition: opacity .2s;
}
.modal-overlay.is-open { opacity: 1; pointer-events: auto; }
.modal {
    background: var(--panel); border: 1px solid var(--border);
    border-radius: var(--r-lg); width: 100%; max-width: 520px;
    max-height: 92vh; display: flex; flex-direction: column;
    transform: translateY(12px); transition: transform .2s;
}
.modal-overlay.is-open .modal { transform: translateY(0); }
.modal-header {
    padding: 20px 24px 16px;
    display: flex; align-items: flex-start; justify-content: space-between; gap: 12px;
    border-bottom: 1px solid var(--border); flex-shrink: 0;
}
.modal-header-left { display: flex; align-items: flex-start; gap: 12px; min-width: 0; }
.modal-icon-box {
    width: 40px; height: 40px; border-radius: var(--r-md);
    background: var(--amber-bg); color: var(--gold-400);
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.modal-title    { font-family: var(--font-display); font-size: 17px; font-weight: 700; }
.modal-subtitle { font-size: 12.5px; color: var(--text-secondary); margin-top: 3px; overflow-wrap: anywhere; }
.modal-close    { background: none; border: none; color: var(--text-tertiary); cursor: pointer; padding: 4px; border-radius: var(--r-sm); flex-shrink: 0; }
.modal-close:hover { color: var(--text); background: var(--panel-alt); }
.modal-body     { padding: 20px 24px; overflow-y: auto; flex: 1; min-height: 0; }
.modal-section-title { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--text-tertiary); margin: 18px 0 10px; }
.modal-actions  { padding: 16px 24px; border-top: 1px solid var(--border); display: flex; gap: 10px; flex-wrap: wrap; justify-content: flex-end; flex-shrink: 0; }
.modal-actions .btn-sm { padding: 8px 16px; font-size: 13px; }

.detail-box { background: var(--panel-alt); border: 1px solid var(--border); border-radius: var(--r-md); padding: 16px 18px; display: flex; flex-direction: column; gap: 10px; }
.detail-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; font-size: 13.5px; }
.detail-row-label { color: var(--text-secondary); }
.detail-row-value { font-weight: 600; color: var(--text); text-align: right; }
.fb-message-box { background: var(--panel-alt); border: 1px solid var(--border); border-radius: var(--r-md); padding: 14px 16px; font-size: 14px; line-height: 1.55; white-space: pre-wrap; overflow-wrap: anywhere; }

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
                <select class="filter-select" name="published" onchange="this.form.submit()">
                    <option value="">All Status</option>
                    <option value="yes" <?php echo $filterPublished === 'yes' ? 'selected' : ''; ?>>Published</option>
                    <option value="no" <?php echo $filterPublished === 'no' ? 'selected' : ''; ?>>Unpublished</option>
                </select>
            </form>
        </div>

        <?php if (empty($feedbackRows)): ?>
            <?php render_table_empty(
                ['Guest', 'Accommodation', 'Rating', 'Category', 'Message', 'Date Submitted', 'Status', 'Actions'],
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
                            <th>Accommodation</th>
                            <th>Rating</th>
                            <th>Category</th>
                            <th>Message</th>
                            <th>Date Submitted</th>
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
                                <td class="td-stay">
                                    <?php echo htmlspecialchars(fb_admin_stay_label($row)); ?>
                                    <?php if (!empty($row['stay_type'])): ?>
                                        <div class="td-sub"><?php echo htmlspecialchars($row['stay_type']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo fb_admin_stars((int) $row['rating']); ?></td>
                                <td><span class="category-badge"><?php echo htmlspecialchars(fb_admin_category_label($row['category'])); ?></span></td>
                                <td class="td-message"><?php echo htmlspecialchars($row['message']); ?></td>
                                <td class="td-date">
                                    <?php echo date('M j, Y', strtotime($row['created_at'])); ?>
                                    <div class="td-sub"><?php echo date('g:i A', strtotime($row['created_at'])); ?></div>
                                </td>
                                <td>
                                    <div class="status-stack">
                                        <span class="ab <?php echo $row['is_published'] ? 'ab--gold' : 'ab--grey'; ?>" id="fb-published-<?php echo (int) $row['id']; ?>">
                                            <?php echo $row['is_published'] ? 'Published' : 'Unpublished'; ?>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <div class="td-actions">
                                        <button
                                            type="button"
                                            class="icon-btn-sm icon-btn-sm--view fb-view-btn"
                                            id="fb-view-btn-<?php echo (int) $row['id']; ?>"
                                            data-id="<?php echo (int) $row['id']; ?>"
                                            data-name="<?php echo htmlspecialchars(fb_admin_name($row), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-email="<?php echo htmlspecialchars($row['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                            data-stay="<?php echo htmlspecialchars(fb_admin_stay_label($row) . (!empty($row['stay_type']) ? ' (' . $row['stay_type'] . ')' : ''), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-category="<?php echo htmlspecialchars(fb_admin_category_label($row['category']), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-message="<?php echo htmlspecialchars($row['message'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-date="<?php echo htmlspecialchars(date('M j, Y · g:i A', strtotime($row['created_at'])), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-published="<?php echo $row['is_published'] ? '1' : '0'; ?>"
                                            title="View"
                                            aria-label="View feedback from <?php echo htmlspecialchars(fb_admin_name($row), ENT_QUOTES, 'UTF-8'); ?>"
                                        >
                                            <?php echo icon('eye', 'icon-sm'); ?>
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
                    'published' => $filterPublished ?: null,
                    'search'    => $filterSearch ?: null,
                ]),
                'entry'
            ); ?>
        <?php endif; ?>
    </div>
</div>

<div class="modal-overlay" id="fbModal" aria-modal="true" role="dialog" aria-labelledby="fbModalName">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-header-left">
                <span class="modal-icon-box"><?php echo icon('message', 'icon-sm'); ?></span>
                <div style="min-width:0;">
                    <div class="modal-title" id="fbModalName">—</div>
                    <div class="modal-subtitle" id="fbModalEmail">—</div>
                </div>
            </div>
            <button type="button" class="modal-close" id="fbModalClose" aria-label="Close"><?php echo icon('close'); ?></button>
        </div>
        <div class="modal-body">
            <div class="detail-box">
                <div class="detail-row"><span class="detail-row-label">Rating</span><span class="detail-row-value" id="fbModalRating"></span></div>
                <div class="detail-row"><span class="detail-row-label">Accommodation</span><span class="detail-row-value" id="fbModalStay">—</span></div>
                <div class="detail-row"><span class="detail-row-label">Category</span><span class="detail-row-value" id="fbModalCategory">—</span></div>
                <div class="detail-row"><span class="detail-row-label">Submitted</span><span class="detail-row-value" id="fbModalDate">—</span></div>
                <div class="detail-row"><span class="detail-row-label">Status</span><span class="detail-row-value"><span class="ab ab--grey" id="fbModalStatus">Unpublished</span></span></div>
            </div>
            <div class="modal-section-title">Message</div>
            <div class="fb-message-box" id="fbModalMessage"></div>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn-sm btn-sm--success" id="fbPublishBtn"><?php echo icon('check', 'icon-sm'); ?> Publish</button>
            <button type="button" class="btn-sm btn-sm--gold" id="fbUnpublishBtn"><?php echo icon('close', 'icon-sm'); ?> Unpublish</button>
        </div>
    </div>
</div>

<div class="toast-wrap" id="toastWrap"></div>

<script>
var CSRF = <?php echo json_encode($csrf); ?>;

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

    var viewBtn = e.target.closest('.fb-view-btn');
    if (viewBtn) {
        openFeedbackModal(viewBtn);
        return;
    }
});

/* ---------- View / moderate modal ---------- */
var fbModal = document.getElementById('fbModal');
var fbActiveBtn = null; // the eye button of the row currently open in the modal

function renderFeedbackModalState() {
    if (!fbActiveBtn) return;
    var published = fbActiveBtn.getAttribute('data-published') === '1';
    var status = document.getElementById('fbModalStatus');
    status.textContent = published ? 'Published' : 'Unpublished';
    status.className = 'ab ' + (published ? 'ab--gold' : 'ab--grey');
    // The action that doesn't apply to the current state is disabled.
    document.getElementById('fbPublishBtn').disabled = published;
    document.getElementById('fbUnpublishBtn').disabled = !published;
}

function openFeedbackModal(btn) {
    fbActiveBtn = btn;
    document.getElementById('fbModalName').textContent     = btn.getAttribute('data-name');
    document.getElementById('fbModalEmail').textContent    = btn.getAttribute('data-email');
    document.getElementById('fbModalStay').textContent     = btn.getAttribute('data-stay') || '—';
    document.getElementById('fbModalCategory').textContent = btn.getAttribute('data-category');
    document.getElementById('fbModalDate').textContent     = btn.getAttribute('data-date');
    document.getElementById('fbModalMessage').textContent  = btn.getAttribute('data-message');

    // Reuse the star markup already rendered in the table row.
    var row = btn.closest('tr');
    var stars = row ? row.querySelector('.fb-stars-admin') : null;
    document.getElementById('fbModalRating').innerHTML = stars ? stars.outerHTML : '';

    renderFeedbackModalState();
    fbModal.classList.add('is-open');
}

function closeFeedbackModal() {
    fbModal.classList.remove('is-open');
    fbActiveBtn = null;
}

function setFeedbackPublished(willPublish) {
    if (!fbActiveBtn) return;
    var btn = fbActiveBtn;
    var id = btn.getAttribute('data-id');
    var pubBtn = document.getElementById('fbPublishBtn');
    var unpubBtn = document.getElementById('fbUnpublishBtn');
    pubBtn.disabled = true;
    unpubBtn.disabled = true;

    feedbackApi({ action: 'toggle_publish', id: id, publish: willPublish })
        .then(function (result) {
            if (!result.ok || !result.data.success) {
                throw new Error((result.data && result.data.error) || 'Could not update this feedback.');
            }
            var badge = document.getElementById('fb-published-' + id);
            if (badge) {
                badge.textContent = willPublish ? 'Published' : 'Unpublished';
                badge.className = 'ab ' + (willPublish ? 'ab--gold' : 'ab--grey');
            }
            btn.setAttribute('data-published', willPublish ? '1' : '0');
            toast(willPublish ? 'Feedback published on the landing page.' : 'Feedback unpublished from the landing page.', 'success');
        })
        .catch(function (err) {
            toast(err.message, 'error');
        })
        .finally(function () {
            renderFeedbackModalState();
        });
}

document.getElementById('fbPublishBtn').addEventListener('click', function () { setFeedbackPublished(true); });
document.getElementById('fbUnpublishBtn').addEventListener('click', function () { setFeedbackPublished(false); });
document.getElementById('fbModalClose').addEventListener('click', closeFeedbackModal);
fbModal.addEventListener('click', function (e) { if (e.target === fbModal) closeFeedbackModal(); });
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && fbModal.classList.contains('is-open')) closeFeedbackModal();
});
</script>


<?php require __DIR__ . '/includes/foot.php'; ?>