<?php
// notifications/index.php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../includes/notifications.php';
require_login();

$me = current_user();
$userId = (int)$me['id'];
$page   = max(1, (int)($_GET['page'] ?? 1));
$per    = 20;
$list   = notif_list($userId, $per, ($page - 1) * $per);
$unreadTotal = notif_unread_count($userId);

$typeLabels = [
    'task.assigned'   => 'Task assignment',
    'task.unassigned' => 'Task reassigned',
    'task.updated'    => 'Task updated',
    'note.shared'     => 'Note shared',
    'note.comment'    => 'New note comment',
];

$typeIcons = [
    'task.assigned'   => '🧭',
    'task.unassigned' => '🔁',
    'task.updated'    => '🛠️',
    'note.shared'     => '🗂️',
    'note.comment'    => '💬',
];

if (!function_exists('notif_relative_time')) {
    function notif_relative_time(?string $timestamp): string {
        if (!$timestamp) {
            return '';
        }
        try {
            $dt = new DateTimeImmutable($timestamp);
        } catch (Throwable $e) {
            return (string)$timestamp;
        }
        $now  = new DateTimeImmutable('now');
        $diff = $now->getTimestamp() - $dt->getTimestamp();
        if ($diff < 0) {
            $diff = 0;
        }
        if ($diff < 60) {
            return $diff . 's ago';
        }
        $mins = (int)floor($diff / 60);
        if ($mins < 60) {
            return $mins . 'm ago';
        }
        $hours = (int)floor($mins / 60);
        if ($hours < 24) {
            return $hours . 'h ago';
        }
        $days = (int)floor($hours / 24);
        if ($days < 7) {
            return $days . 'd ago';
        }
        return $dt->format('M j, Y');
    }
}

if (!function_exists('notif_normalize_search')) {
    function notif_normalize_search(string $value): string {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }
        return strtolower($value);
    }
}

$todayCount = 0;
$weekCount  = 0;
$totalCount = count($list);
$groups     = [];

try {
    $now = new DateTimeImmutable('now');
    $todayStart = $now->setTime(0, 0, 0);
} catch (Throwable $e) {
    $todayStart = null;
}
$yesterdayStart = $todayStart ? $todayStart->modify('-1 day') : null;
$weekStart      = $todayStart ? $todayStart->modify('-6 days') : null;

foreach ($list as $row) {
    $typeKey = (string)($row['type'] ?? 'general');
    $label   = $typeLabels[$typeKey] ?? ucwords(str_replace(['.', '_'], ' ', $typeKey));
    $icon    = $typeIcons[$typeKey] ?? '🔔';
    $title   = trim((string)($row['title'] ?? ''));
    if ($title === '') {
        $title = $label;
    }
    $body = trim((string)($row['body'] ?? ''));

    $category = 'other';
    if (strpos($typeKey, 'task') === 0) {
        $category = 'task';
    } elseif (strpos($typeKey, 'note') === 0) {
        $category = 'note';
    }

    $createdAtRaw = $row['created_at'] ?? null;
    $createdAt    = null;
    $dayKey       = 'unknown';
    $dayLabel     = 'Earlier';
    $dayTag       = 'older';
    $dayDateLabel = '';
    $timeAttr     = '';
    $timeDisplay  = '';
    $timeRel      = notif_relative_time($createdAtRaw);
    $weekFlag     = 0;

    if ($createdAtRaw) {
        try {
            $createdAt = new DateTimeImmutable($createdAtRaw);
        } catch (Throwable $e) {
            $createdAt = null;
        }
    }

    if ($createdAt) {
        $dayKey = $createdAt->format('Y-m-d');
        $timeAttr = $createdAt->format(DateTimeInterface::ATOM);
        $timeDisplay = trim($createdAt->format('g:i A') . ($timeRel ? ' · ' . $timeRel : ''));
        if ($todayStart && $createdAt >= $todayStart) {
            $dayLabel = 'Today';
            $dayTag   = 'today';
            $todayCount++;
        } elseif ($yesterdayStart && $createdAt >= $yesterdayStart) {
            $dayLabel = 'Yesterday';
            $dayTag   = 'yesterday';
        } elseif ($weekStart && $createdAt >= $weekStart) {
            $dayLabel = $createdAt->format('l');
            $dayTag   = 'week';
        } else {
            $dayLabel = $createdAt->format('M j, Y');
            $dayTag   = 'older';
        }
        $dayDateLabel = $createdAt->format('M j, Y');
        if ($weekStart && $createdAt >= $weekStart) {
            $weekFlag = 1;
            if ($dayTag !== 'today') {
                $weekCount++;
            }
        }
        if ($dayTag === 'today') {
            $weekCount++;
        }
    }

    if (!isset($groups[$dayKey])) {
        $groups[$dayKey] = [
            'label' => $dayLabel,
            'tag'   => $dayTag,
            'date'  => $dayDateLabel,
            'items' => [],
        ];
    }

    $searchBlob = notif_normalize_search($title . ' ' . $body . ' ' . $label);

    $groups[$dayKey]['items'][] = [
        'id'            => (int)($row['id'] ?? 0),
        'title'         => $title,
        'body'          => $body,
        'label'         => $label,
        'icon'          => $icon,
        'url'           => $row['url'] ?? null,
        'is_unread'     => empty($row['is_read']),
        'type_key'      => $typeKey,
        'category'      => $category,
        'day_tag'       => $dayTag,
        'week_flag'     => $weekFlag,
        'search_blob'   => $searchBlob,
        'time_attr'     => $timeAttr,
        'time_display'  => $timeDisplay ?: ($timeRel ?: ''),
        'time_relative' => $timeRel,
    ];
}

$hasNotifications = $totalCount > 0;
$emptyTitle   = $hasNotifications ? 'No notifications match your filters' : 'You’re all caught up';
$emptyMessage = $hasNotifications
    ? 'Clear filters or adjust your search to see more updates.'
    : 'When new activity arrives, it will appear here automatically.';

$title = 'Notifications';
include __DIR__ . '/../includes/header.php';
?>
<section class="notifications-board">
  <aside class="notifications-board__side">
    <header class="notif-side__header">
      <h1>Notifications</h1>
      <p class="notif-side__hint">Catch up on recent activity without leaving this page.</p>
    </header>
    <div class="notif-count<?php echo $unreadTotal ? '' : ' is-zero'; ?>" data-unread-wrapper>
      <span class="notif-count__value" data-unread-count><?php echo (int)$unreadTotal; ?></span>
      <span class="notif-count__label">Unread</span>
    </div>
    <div class="notif-side__section">
      <h2 class="notif-section-title">At a glance</h2>
      <div class="notif-summary">
        <article class="notif-summary__item" data-stat="unread">
          <span class="notif-stat__label">Unread</span>
          <span class="notif-stat__value"><?php echo (int)$unreadTotal; ?></span>
          <span class="notif-stat__hint">Awaiting review</span>
        </article>
        <article class="notif-summary__item" data-stat="today">
          <span class="notif-stat__label">Today</span>
          <span class="notif-stat__value"><?php echo (int)$todayCount; ?></span>
          <span class="notif-stat__hint">Arrived since midnight</span>
        </article>
        <article class="notif-summary__item" data-stat="week">
          <span class="notif-stat__label">This week</span>
          <span class="notif-stat__value"><?php echo (int)$weekCount; ?></span>
          <span class="notif-stat__hint">Past seven days</span>
        </article>
        <article class="notif-summary__item" data-stat="total">
          <span class="notif-stat__label">Listed here</span>
          <span class="notif-stat__value"><?php echo (int)$totalCount; ?></span>
          <span class="notif-stat__hint">Matching filters</span>
        </article>
      </div>
    </div>
    <div class="notif-side__section">
      <h2 class="notif-section-title">Filter by</h2>
      <div class="notif-filter" role="group" aria-label="Notification filters">
        <button type="button" class="notif-filter__btn is-active" data-filter="all" aria-pressed="true">All</button>
        <button type="button" class="notif-filter__btn" data-filter="unread" aria-pressed="false">Unread</button>
        <button type="button" class="notif-filter__btn" data-filter="recent" aria-pressed="false">Recent</button>
        <button type="button" class="notif-filter__btn" data-filter="task" aria-pressed="false">Tasks</button>
        <button type="button" class="notif-filter__btn" data-filter="note" aria-pressed="false">Notes</button>
        <button type="button" class="notif-filter__btn" data-filter="other" aria-pressed="false">Other</button>
      </div>
    </div>
  </aside>

  <div class="notifications-board__main">
    <div class="notif-main__toolbar">
      <div class="notif-main__status" data-match-text>
        Showing <strong data-match-count><?php echo (int)$totalCount; ?></strong>
        <span data-match-label><?php echo $totalCount === 1 ? 'update' : 'updates'; ?></span>
      </div>
      <div class="notif-main__actions">
        <form method="post" action="/notifications/api.php" class="inline" data-action="mark-all">
          <input type="hidden" name="action" value="mark_all_read">
          <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
          <button class="btn primary small" type="submit" <?php echo $unreadTotal ? '' : 'disabled'; ?>>Mark all read</button>
        </form>
        <button type="button" class="btn ghost small" data-refresh>Refresh</button>
      </div>
    </div>

    <div class="notif-searchbar">
      <span class="notif-searchbar__icon" aria-hidden="true">🔍</span>
      <input type="search" class="notif-searchbar__input" placeholder="Search notifications" autocomplete="off" data-search>
      <button type="button" class="notif-searchbar__clear" data-clear-search>Clear</button>
    </div>

    <?php if ($hasNotifications): ?>
      <div class="notif-groups" data-feed>
        <?php foreach ($groups as $group): ?>
          <section class="notif-group" data-day-section data-day-tag="<?php echo sanitize($group['tag']); ?>">
            <header class="notif-group__header">
              <div>
                <h2 class="notif-group__title"><?php echo sanitize($group['label']); ?></h2>
                <?php if (!empty($group['date'])): ?>
                  <span class="notif-group__date"><?php echo sanitize($group['date']); ?></span>
                <?php endif; ?>
              </div>
              <span class="notif-group__count" data-day-count><?php $count = count($group['items']); echo $count === 1 ? '1 update' : $count . ' updates'; ?></span>
            </header>
            <div class="notif-group__list">
              <?php foreach ($group['items'] as $item):
                $isUnread = !empty($item['is_unread']);
                $searchBlob = sanitize($item['search_blob']);
                $timeAttr   = $item['time_attr'] ?? '';
                $timeDisplay = $item['time_display'] ?: ($item['time_relative'] ?? '');
              ?>
                <article class="notif-item<?php echo $isUnread ? ' is-unread' : ''; ?>" data-entry data-id="<?php echo (int)$item['id']; ?>" data-type="<?php echo sanitize($item['type_key']); ?>" data-category="<?php echo sanitize($item['category']); ?>" data-read="<?php echo $isUnread ? '0' : '1'; ?>" data-search="<?php echo $searchBlob; ?>" data-day-tag="<?php echo sanitize($item['day_tag']); ?>" data-week="<?php echo !empty($item['week_flag']) ? '1' : '0'; ?>">
                  <div class="notif-item__icon" aria-hidden="true"><?php echo $item['icon']; ?></div>
                  <div class="notif-item__body">
                    <div class="notif-item__head">
                      <span class="notif-item__title"><?php echo sanitize($item['title']); ?></span>
                      <span class="notif-item__badge"><?php echo sanitize($item['label']); ?></span>
                      <span class="notif-item__status<?php echo $isUnread ? ' is-unread' : ''; ?>" data-status><?php echo $isUnread ? 'Unread' : 'Read'; ?></span>
                    </div>
                    <?php if ($item['body'] !== ''): ?>
                      <p class="notif-item__text"><?php echo nl2br(sanitize($item['body'])); ?></p>
                    <?php endif; ?>
                    <div class="notif-item__meta">
                      <?php if ($timeAttr): ?>
                        <time class="notif-item__time" datetime="<?php echo sanitize($timeAttr); ?>"><?php echo sanitize($timeDisplay); ?></time>
                      <?php elseif ($timeDisplay): ?>
                        <span class="notif-item__time"><?php echo sanitize($timeDisplay); ?></span>
                      <?php endif; ?>
                      <div class="notif-item__actions">
                        <?php if (!empty($item['url'])): ?>
                          <a class="btn ghost xsmall" href="<?php echo sanitize($item['url']); ?>">Open</a>
                        <?php endif; ?>
                        <form method="post" action="/notifications/api.php" class="notif-item__toggle" data-action="toggle-read">
                          <input type="hidden" name="id" value="<?php echo (int)$item['id']; ?>">
                          <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
                          <button type="submit" class="btn ghost xsmall" name="action" value="mark_read" data-toggle-read <?php echo $isUnread ? '' : 'hidden'; ?>>Mark read</button>
                          <button type="submit" class="btn ghost xsmall" name="action" value="mark_unread" data-toggle-unread <?php echo $isUnread ? 'hidden' : ''; ?>>Mark unread</button>
                        </form>
                      </div>
                    </div>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="notif-empty" data-empty role="status"
         data-base-title="You’re all caught up"
         data-base-message="When new activity arrives, it will appear here automatically."
         data-filter-title="No notifications match"
         data-filter-message="Clear filters or adjust your search to see more updates."
         <?php echo $hasNotifications ? 'hidden' : ''; ?>>
      <div class="notif-empty__icon">📭</div>
      <h2 data-empty-title><?php echo sanitize($emptyTitle); ?></h2>
      <p class="muted" data-empty-message><?php echo sanitize($emptyMessage); ?></p>
      <?php if ($hasNotifications): ?>
        <button type="button" class="btn ghost small" data-empty-reset>Reset filters</button>
      <?php endif; ?>
    </div>
  </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const feed = document.querySelector('[data-feed]');
  const entries = feed ? Array.from(feed.querySelectorAll('[data-entry]')) : [];
  const countNode = document.querySelector('[data-unread-count]');
  const wrapper = document.querySelector('[data-unread-wrapper]');
  const dot = document.getElementById('notifDot');
  const markAllForm = document.querySelector('form[data-action="mark-all"]');
  const markAllBtn = markAllForm ? markAllForm.querySelector('button[type="submit"]') : null;
  const filterButtons = document.querySelectorAll('[data-filter]');
  const searchInput = document.querySelector('[data-search]');
  const clearSearch = document.querySelector('[data-clear-search]');
  const matchCountNode = document.querySelector('[data-match-count]');
  const matchLabelNode = document.querySelector('[data-match-label]');
  const emptyState = document.querySelector('[data-empty]');
  const emptyTitle = emptyState ? emptyState.querySelector('[data-empty-title]') : null;
  const emptyMessage = emptyState ? emptyState.querySelector('[data-empty-message]') : null;
  const emptyReset = emptyState ? emptyState.querySelector('[data-empty-reset]') : null;
  const statNodes = {
    unread: document.querySelector('[data-stat="unread"] .notif-stat__value'),
    today: document.querySelector('[data-stat="today"] .notif-stat__value'),
    week: document.querySelector('[data-stat="week"] .notif-stat__value'),
    total: document.querySelector('[data-stat="total"] .notif-stat__value')
  };
  const matchesSuffix = { singular: 'update', plural: 'updates' };
  let currentFilter = 'all';

  function getUnreadCount() {
    return entries.filter(entry => entry.dataset.read === '0').length;
  }

  function updateMatchDisplay(value) {
    if (matchCountNode) {
      matchCountNode.textContent = value;
    }
    if (matchLabelNode) {
      matchLabelNode.textContent = value === 1 ? matchesSuffix.singular : matchesSuffix.plural;
    }
  }

  function calculateStats() {
    const unread = getUnreadCount();
    const today = entries.filter(entry => entry.dataset.dayTag === 'today').length;
    const week = entries.filter(entry => entry.dataset.week === '1').length;
    const total = entries.length;
    if (statNodes.unread) statNodes.unread.textContent = unread;
    if (statNodes.today) statNodes.today.textContent = today;
    if (statNodes.week) statNodes.week.textContent = week;
    if (statNodes.total) statNodes.total.textContent = total;
  }

  function renderCount(value) {
    const count = Math.max(0, Number.isFinite(value) ? value : getUnreadCount());
    if (countNode) {
      countNode.textContent = count;
    }
    if (wrapper) {
      wrapper.classList.toggle('is-zero', count === 0);
    }
    if (dot) {
      if (count > 0) {
        dot.textContent = count;
        dot.style.display = 'inline-block';
      } else {
        dot.style.display = 'none';
      }
    }
    if (markAllBtn) {
      markAllBtn.disabled = count === 0;
    }
    if (statNodes.unread) {
      statNodes.unread.textContent = count;
    }
  }

  function updateDaySections(visibleMap) {
    document.querySelectorAll('[data-day-section]').forEach(section => {
      const visibleCount = visibleMap.get(section) || 0;
      const countNode = section.querySelector('[data-day-count]');
      section.hidden = visibleCount === 0;
      if (countNode) {
        countNode.textContent = visibleCount === 1 ? '1 update' : `${visibleCount} updates`;
      }
    });
  }

  function applyFilters() {
    if (!entries.length) {
      if (emptyState) {
        emptyState.hidden = false;
        if (emptyTitle) {
          emptyTitle.textContent = emptyState.dataset.baseTitle || 'You’re all caught up';
        }
        if (emptyMessage) {
          emptyMessage.textContent = emptyState.dataset.baseMessage || 'When new activity arrives, it will appear here automatically.';
        }
        if (emptyReset) {
          emptyReset.hidden = true;
        }
      }
      updateMatchDisplay(0);
      updateDaySections(new Map());
      return;
    }

    const query = (searchInput ? searchInput.value : '').trim().toLowerCase();
    const filter = currentFilter;
    const visibleMap = new Map();
    let visible = 0;

    entries.forEach(entry => {
      const category = entry.dataset.category || 'other';
      const isUnread = entry.dataset.read === '0';
      const matchesFilter = filter === 'all'
        || (filter === 'unread' && isUnread)
        || (filter === category)
        || (filter === 'recent' && (entry.dataset.dayTag === 'today' || entry.dataset.dayTag === 'yesterday'));
      const haystack = entry.dataset.search || '';
      const matchesSearch = !query || haystack.indexOf(query) !== -1;

      const show = matchesFilter && matchesSearch;
      entry.hidden = !show;
      entry.classList.toggle('is-hidden', !show);

      if (show) {
        visible += 1;
        const section = entry.closest('[data-day-section]');
        if (section) {
          visibleMap.set(section, (visibleMap.get(section) || 0) + 1);
        }
      }
    });

    updateDaySections(visibleMap);
    updateMatchDisplay(visible);

    if (emptyState) {
      if (visible === 0) {
        emptyState.hidden = false;
        if (emptyTitle) {
          emptyTitle.textContent = emptyState.dataset.filterTitle || 'No notifications match';
        }
        if (emptyMessage) {
          emptyMessage.textContent = emptyState.dataset.filterMessage || 'Clear filters or adjust your search to see more updates.';
        }
        if (emptyReset) {
          emptyReset.hidden = false;
        }
      } else {
        emptyState.hidden = true;
        if (emptyReset) {
          emptyReset.hidden = true;
        }
      }
    }
  }

  calculateStats();
  renderCount(getUnreadCount());
  applyFilters();

  filterButtons.forEach(btn => {
    btn.addEventListener('click', () => {
      currentFilter = btn.dataset.filter || 'all';
      filterButtons.forEach(b => {
        const isActive = b === btn;
        b.classList.toggle('is-active', isActive);
        b.setAttribute('aria-pressed', isActive ? 'true' : 'false');
      });
      applyFilters();
    });
  });

  if (searchInput) {
    searchInput.addEventListener('input', () => {
      applyFilters();
      if (clearSearch) {
        clearSearch.hidden = searchInput.value.length === 0;
      }
    });
  }

  if (clearSearch) {
    clearSearch.addEventListener('click', () => {
      if (searchInput) {
        searchInput.value = '';
      }
      clearSearch.hidden = true;
      applyFilters();
      if (searchInput) {
        searchInput.focus();
      }
    });
    if (!searchInput || !searchInput.value) {
      clearSearch.hidden = true;
    }
  }

  if (emptyReset) {
    emptyReset.addEventListener('click', () => {
      currentFilter = 'all';
      filterButtons.forEach(b => {
        const isActive = b.dataset.filter === 'all';
        b.classList.toggle('is-active', isActive);
        b.setAttribute('aria-pressed', isActive ? 'true' : 'false');
      });
      if (searchInput) {
        searchInput.value = '';
      }
      if (clearSearch) {
        clearSearch.hidden = true;
      }
      applyFilters();
    });
  }

  async function postForm(form, submitter) {
    const data = new FormData(form);
    if (submitter && submitter.name) {
      data.append(submitter.name, submitter.value);
    }
    const res = await fetch(form.action, {
      method: 'POST',
      body: data,
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json'
      }
    });
    if (!res.ok) {
      throw new Error('Request failed');
    }
    try {
      return await res.json();
    } catch (err) {
      throw new Error('Invalid response');
    }
  }

  function handleToggle(form) {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const submitter = event.submitter;
      if (!submitter) {
        form.submit();
        return;
      }
      const action = submitter.value;
      try {
        const json = await postForm(form, submitter);
        if (!json || !json.ok) {
          return;
        }
        const parent = form.closest('[data-entry]');
        if (!parent) {
          return;
        }
        const makeUnread = action === 'mark_unread';
        parent.dataset.read = makeUnread ? '0' : '1';
        parent.classList.toggle('is-unread', makeUnread);
        const statusNode = parent.querySelector('[data-status]');
        if (statusNode) {
          statusNode.textContent = makeUnread ? 'Unread' : 'Read';
          statusNode.classList.toggle('is-unread', makeUnread);
        }
        const readBtn = form.querySelector('[data-toggle-read]');
        const unreadBtn = form.querySelector('[data-toggle-unread]');
        if (readBtn) {
          readBtn.hidden = !makeUnread;
        }
        if (unreadBtn) {
          unreadBtn.hidden = makeUnread;
        }
        renderCount(json.count);
        calculateStats();
        applyFilters();
      } catch (err) {
        console.error(err);
        form.submit();
      }
    });
  }

  document.querySelectorAll('form[data-action="toggle-read"]').forEach(handleToggle);

  if (markAllForm) {
    markAllForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (!confirm('Mark all notifications as read?')) {
        return;
      }
      try {
        const json = await postForm(markAllForm);
        if (!json || !json.ok) {
          return;
        }
        entries.forEach(entry => {
          entry.dataset.read = '1';
          entry.classList.remove('is-unread');
          const statusNode = entry.querySelector('[data-status]');
          if (statusNode) {
            statusNode.textContent = 'Read';
            statusNode.classList.remove('is-unread');
          }
          const toggle = entry.querySelector('form[data-action="toggle-read"]');
          if (toggle) {
            const readBtn = toggle.querySelector('[data-toggle-read]');
            const unreadBtn = toggle.querySelector('[data-toggle-unread]');
            if (readBtn) {
              readBtn.hidden = true;
            }
            if (unreadBtn) {
              unreadBtn.hidden = false;
            }
          }
        });
        renderCount(json.count);
        calculateStats();
        applyFilters();
      } catch (err) {
        console.error(err);
        markAllForm.submit();
      }
    });
  }

  const refreshBtn = document.querySelector('[data-refresh]');
  if (refreshBtn) {
    refreshBtn.addEventListener('click', () => {
      window.location.reload();
    });
  }

  if (clearSearch && searchInput && !searchInput.value) {
    clearSearch.hidden = true;
  }
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
