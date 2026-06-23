// ============================================
// TOGGLE SIDEBAR (DESKTOP)
// ============================================
function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');

    if (window.innerWidth > 1024) {
        sidebar.classList.toggle('collapsed');
        localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
    }
}

// ============================================
// TOGGLE MOBILE SIDEBAR
// ============================================
function toggleMobileSidebar() {
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.querySelector('.sidebar-overlay');

    sidebar.classList.toggle('active');
    if (overlay) overlay.classList.toggle('active');

    if (sidebar.classList.contains('active')) {
        document.body.style.overflow = 'hidden';
    } else {
        document.body.style.overflow = '';
    }
}

// ============================================
// CLOSE MOBILE SIDEBAR
// ============================================
function closeMobileSidebar() {
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.querySelector('.sidebar-overlay');

    sidebar.classList.remove('active');
    if (overlay) overlay.classList.remove('active');
    document.body.style.overflow = '';
}

// ============================================
// TOGGLE DARK MODE
// ============================================
function toggleDarkMode() {
    document.body.classList.toggle('dark-mode');
    localStorage.setItem('darkMode', document.body.classList.contains('dark-mode'));
}

// ============================================
// CHECK DARK MODE ON LOAD
// ============================================
if (localStorage.getItem('darkMode') === 'true') {
    document.body.classList.add('dark-mode');
}

// ============================================
// CHECK SIDEBAR STATE ON LOAD (DESKTOP ONLY)
// ============================================
if (window.innerWidth > 1024 && localStorage.getItem('sidebarCollapsed') === 'true') {
    document.querySelector('.sidebar').classList.add('collapsed');
}

// ============================================
// MODAL FUNCTIONS
// ============================================
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('active');
        const sidebar = document.querySelector('.sidebar');
        if (!sidebar || !sidebar.classList.contains('active')) {
            document.body.style.overflow = '';
        }
    }
}

// Close modal on overlay click
document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.remove('active');
            const sidebar = document.querySelector('.sidebar');
            if (!sidebar || !sidebar.classList.contains('active')) {
                document.body.style.overflow = '';
            }
        }
    });
});

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.active').forEach(modal => {
            modal.classList.remove('active');
        });
        closeMobileSidebar();
    }
});

// ============================================
// TOAST NOTIFICATION
// ============================================
function showToast(message, type) {
    if (!type) type = 'success';
    document.querySelectorAll('.toast').forEach(function(t) { t.remove(); });

    var toast = document.createElement('div');
    toast.className = 'toast ' + type;
    var icon = type === 'success' ? 'check-circle' : (type === 'error' ? 'times-circle' : 'exclamation-circle');
    toast.innerHTML = '<i class="fas fa-' + icon + '"></i> ' + message;
    document.body.appendChild(toast);

    toast.offsetHeight;

    setTimeout(function() { toast.classList.add('show'); }, 10);
    setTimeout(function() {
        toast.classList.remove('show');
        setTimeout(function() { toast.remove(); }, 300);
    }, 3000);
}

// ============================================
// LOADING
// ============================================
function showLoading() {
    var overlay = document.getElementById('loadingOverlay');
    if (overlay) overlay.classList.add('active');
}

function hideLoading() {
    var overlay = document.getElementById('loadingOverlay');
    if (overlay) overlay.classList.remove('active');
}

// ============================================
// SEARCH TABLE
// ============================================
function searchTable(inputId, tableId) {
    var input = document.getElementById(inputId);
    var filter = input.value.toLowerCase();
    var table = document.getElementById(tableId);
    if (!table) return;
    var tr = table.getElementsByTagName('tr');

    for (var i = 1; i < tr.length; i++) {
        var td = tr[i].getElementsByTagName('td');
        var found = false;
        for (var j = 0; j < td.length; j++) {
            if (td[j]) {
                var txtValue = td[j].textContent || td[j].innerText;
                if (txtValue.toLowerCase().indexOf(filter) > -1) {
                    found = true;
                    break;
                }
            }
        }
        tr[i].style.display = found ? '' : 'none';
    }
}

// ============================================
// FILTER TABLE BY DATE
// ============================================
function filterByDate(tableId, dateFrom, dateTo) {
    var table = document.getElementById(tableId);
    if (!table) return;
    var tr = table.getElementsByTagName('tr');
    var from = new Date(dateFrom);
    var to = new Date(dateTo);

    for (var i = 1; i < tr.length; i++) {
        var dateCell = tr[i].getElementsByTagName('td')[1];
        if (dateCell) {
            var rowDate = new Date(dateCell.textContent);
            if (rowDate >= from && rowDate <= to) {
                tr[i].style.display = '';
            } else {
                tr[i].style.display = 'none';
            }
        }
    }
}

// ============================================
// CONFIRM DELETE
// ============================================
function confirmDelete(message) {
    if (!message) message = 'Apakah Anda yakin ingin menghapus data ini?';
    return confirm(message);
}

// ============================================
// PRINT PAGE
// ============================================
function printPage() {
    window.print();
}

// ============================================
// EXPORT TO CSV
// ============================================
function exportToCSV(tableId, filename) {
    var table = document.getElementById(tableId);
    if (!table) return;
    var csv = [];
    var rows = table.querySelectorAll('tr');

    for (var i = 0; i < rows.length; i++) {
        var row = [], cols = rows[i].querySelectorAll('td, th');
        for (var j = 0; j < cols.length; j++) {
            row.push('"' + (cols[j].innerText || '').replace(/"/g, '""') + '"');
        }
        csv.push(row.join(','));
    }

    var csvFile = new Blob(
        [csv.join('\n')],
        {type: 'text/csv;charset=utf-8;'});
    var downloadLink = document.createElement('a');
    downloadLink.download = filename + '.csv';
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.click();
}

// ============================================
// CLOCK
// ============================================
function updateClock() {
    var now = new Date();
    var clock = document.getElementById('clock');
    if (clock) {
        clock.textContent = now.toLocaleTimeString('id-ID', {hour: '2-digit', minute:'2-digit', second:'2-digit'});
    }
}

setInterval(updateClock, 1000);

// ============================================
// AUTO-HIDE ALERTS
// ============================================
document.querySelectorAll('.alert').forEach(function(alert) {
    setTimeout(function() {
        alert.style.opacity = '0';
        setTimeout(function() { alert.remove(); }, 300);
    }, 5000);
});

// ============================================
// FORM VALIDATION
// ============================================
function validateForm(formId) {
    var form = document.getElementById(formId);
    if (!form) return false;
    var inputs = form.querySelectorAll('[required]');
    var valid = true;

    inputs.forEach(function(input) {
        if (!input.value.trim()) {
            input.style.borderColor = 'var(--danger)';
            valid = false;
        } else {
            input.style.borderColor = '#e2e8f0';
        }
    });

    return valid;
}

// ============================================
// TAB SWITCHING
// ============================================
function switchTab(tabId) {
    document.querySelectorAll('.tab-content').forEach(function(tab) { tab.classList.remove('active'); });
    document.querySelectorAll('.tab-btn').forEach(function(btn) { btn.classList.remove('active'); });
    var tabContent = document.getElementById(tabId);
    if (tabContent) tabContent.classList.add('active');
    if (event && event.target) event.target.classList.add('active');
}

// ============================================
// CHART.JS DEFAULTS
// ============================================
if (typeof Chart !== 'undefined') {
    Chart.defaults.font.family = "'Segoe UI', sans-serif";
    Chart.defaults.color = '#64748b';
}

// ============================================
// REFRESH PENGUMUMAN BADGE
// ============================================
function refreshPengumumanBadge() {
    fetch('ajax_unread_count.php')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var badges = document.querySelectorAll('.nav-item .badge, .nav-icon .notif-count');
            badges.forEach(function(b) {
                if (data.count > 0) {
                    b.textContent = data.count;
                    b.style.display = '';
                } else {
                    b.style.display = 'none';
                }
            });
        })
        .catch(function(err) { console.log('Badge refresh error:', err); });
}

// ============================================
// WINDOW RESIZE HANDLER
// ============================================
window.addEventListener('resize', function() {
    var sidebar = document.querySelector('.sidebar');
    var overlay = document.querySelector('.sidebar-overlay');

    if (window.innerWidth > 1024) {
        sidebar.classList.remove('active');
        if (overlay) overlay.classList.remove('active');
        document.body.style.overflow = '';

        if (localStorage.getItem('sidebarCollapsed') === 'true') {
            sidebar.classList.add('collapsed');
        } else {
            sidebar.classList.remove('collapsed');
        }
    } else {
        sidebar.classList.remove('collapsed');
    }
});

// ============================================
// INIT ON DOM READY
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    var overlay = document.querySelector('.sidebar-overlay');
    if (overlay) {
        overlay.addEventListener('click', closeMobileSidebar);
    }

    document.querySelectorAll('.nav-item').forEach(function(item) {
        item.addEventListener('click', function() {
            if (window.innerWidth <= 1024) {
                closeMobileSidebar();
            }
        });
    });

    var touchStartX = 0;
    var sidebar = document.querySelector('.sidebar');

    if (sidebar) {
        sidebar.addEventListener('touchstart', function(e) {
            touchStartX = e.touches[0].clientX;
        }, {passive: true});

        sidebar.addEventListener('touchmove', function(e) {
            var touchX = e.touches[0].clientX;
            var diff = touchStartX - touchX;
            if (diff > 80 && window.innerWidth <= 1024) {
                closeMobileSidebar();
            }
        }, {passive: true});
    }

    updateClock();
});