'use strict';

// ── i18n (populated from server data in renderTree) ─────────────────────────
var _i18n = {};
var _urls = {};

// ── Helpers ──────────────────────────────────────────────────────────────────

function esc(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/** Wrap CJK and Latin runs in <div lang="…"> and insert <br> at boundaries. */
function brName(name) {
    var safe = esc(name);
    // Split into CJK vs non-CJK runs
    var parts = safe.split(/([\u3000-\u9FFF\uF900-\uFAFF]+)/);
    return parts.map(function(p) {
        if (!p) return '';
        if (/[\u3000-\u9FFF\uF900-\uFAFF]/.test(p)) {
            return '<div lang="zh-Hant">' + p + '</div>';
        }
        return '<div lang="en">' + p + '</div>';
    }).join('');
}

function formatYears(birth, death, birthApprox, deathApprox) {
    birth = birth || null;
    death = death || null;
    var b = birth ? (birth + (birthApprox ? ' (?)' : '')) : null;
    var d = death ? (death + (deathApprox ? ' (?)' : '')) : null;
    if (!b && !d) return '';
    if (b && d) return b + ' \u2013 ' + d;   // en-dash range
    if (b) return 'b.\u00a0' + b;
    return 'd.\u00a0' + d;
}

// ── DOM builders ─────────────────────────────────────────────────────────────

function buildPersonCard(person, editMode) {
    const card = document.createElement('div');
    card.className = 'person-card';
    card.dataset.id = person.id;

    // Edit link (above photo, hidden in print)
    if (editMode) {
        const link = document.createElement('a');
        link.href = _urls.editMember.replace('__ID__', person.id);
        link.className = 'edit-link no-print';
        link.textContent = _i18n.edit || '\u270f\ufe0f Edit';
        card.appendChild(link);
    }

    // Photo or emoji placeholder
    if (person.photo_filename) {
        const img = document.createElement('img');
        img.src = _urls.photo.replace('__ID__', person.id);
        img.alt = esc(person.name);
        img.className = 'person-photo';
        card.appendChild(img);
    } else {
        const ph = document.createElement('div');
        ph.className = 'person-photo person-emoji';
        ph.textContent = '\uD83D\uDC6A';   // 👪  U+1F46A
        card.appendChild(ph);
    }

    // Name
    const nameEl = document.createElement('div');
    nameEl.className = 'person-name';
    nameEl.innerHTML = brName(person.name);
    card.appendChild(nameEl);

    // Years (only if at least one is present)
    const yStr = formatYears(person.birth_year, person.death_year,
                             person.birth_year_approx, person.death_year_approx);
    if (yStr) {
        const yearsEl = document.createElement('div');
        yearsEl.className = 'person-years';
        yearsEl.textContent = yStr;
        card.appendChild(yearsEl);
    }

    return card;
}

function buildSpouseConnector(marriageInfo) {
    const conn = document.createElement('div');
    conn.className = 'spouse-connector';

    if (marriageInfo) {
        const ym = marriageInfo.year_married;
        const ys = marriageInfo.year_separated;
        if (ym || ys) {
            const span = document.createElement('span');
            span.className = 'marriage-years';
            if (ym && ys) {
                span.innerHTML = (ym + (marriageInfo.year_married_approx ? ' (?)' : '')) +
                    '<br>' + (ys + (marriageInfo.year_separated_approx ? ' (?)' : ''));
            } else if (ym) {
                span.textContent = ym + (marriageInfo.year_married_approx ? ' (?)' : '');
            } else {
                span.textContent = ys + (marriageInfo.year_separated_approx ? ' (?)' : '');
            }
            conn.appendChild(span);
        }
    }
    return conn;
}

function buildFamilyNode(node, editMode) {
    const family = document.createElement('div');
    family.className = 'tree-family';

    // ── Couple row ──
    const couple = document.createElement('div');
    couple.className = 'tree-couple';

    // Head person's additional spouses go on the LEFT side of the head,
    // so the layout reads: ExtraSpouse [conn] Head [conn] PrimarySpouse
    // This makes it clear both connect to the head, not to each other.
    if (node.allSpouses && node.allSpouses.length > 1 && node.spouse) {
        var primaryId = node.spouse.id;
        node.allSpouses.forEach(function(sp) {
            if (sp.id == primaryId) return;
            var spPerson = { id: sp.id, name: sp.name };
            if (window.TREE_DATA && window.TREE_DATA.allPersons) {
                var full = window.TREE_DATA.allPersons[sp.id];
                if (full) spPerson = full;
            }
            couple.appendChild(buildPersonCard(spPerson, editMode));
            var spouseInfo = {
                year_married: sp.year_married,
                year_separated: sp.year_separated,
                year_married_approx: sp.year_married_approx,
                year_separated_approx: sp.year_separated_approx
            };
            var conn = buildSpouseConnector(spouseInfo);
            conn.dataset.spouseId = sp.id;
            couple.appendChild(conn);
        });
    }

    var headCard = buildPersonCard(node.person, editMode);
    headCard.classList.add('head-person');
    couple.appendChild(headCard);

    if (node.spouse) {
        var primaryConn = buildSpouseConnector(node.marriageInfo);
        primaryConn.dataset.primary = '1';
        couple.appendChild(primaryConn);
        couple.appendChild(buildPersonCard(node.spouse, editMode));
    }

    // Additional spouses of the primary spouse go on the RIGHT side
    if (node.spouseAllSpouses && node.spouseAllSpouses.length > 0) {
        node.spouseAllSpouses.forEach(function(sp) {
            var spouseInfo = {
                year_married: sp.year_married,
                year_separated: sp.year_separated,
                year_married_approx: sp.year_married_approx,
                year_separated_approx: sp.year_separated_approx
            };
            var conn = buildSpouseConnector(spouseInfo);
            conn.dataset.spouseId = sp.id;
            couple.appendChild(conn);
            var spPerson = { id: sp.id, name: sp.name };
            if (window.TREE_DATA && window.TREE_DATA.allPersons) {
                var full = window.TREE_DATA.allPersons[sp.id];
                if (full) spPerson = full;
            }
            couple.appendChild(buildPersonCard(spPerson, editMode));
        });
    }

    family.appendChild(couple);

    // Helper: add a tree-children row with toggle button
    function addChildrenGroup(childNodes, spouseId) {
        if (!childNodes || !childNodes.length) return;
        var childrenRow = document.createElement('div');
        childrenRow.className = 'tree-children';
        if (spouseId) childrenRow.dataset.spouseId = spouseId;
        childNodes.forEach(function(child) {
            childrenRow.appendChild(buildFamilyNode(child, editMode));
        });
        family.appendChild(childrenRow);

        var toggle = document.createElement('button');
        toggle.className = 'branch-toggle no-print';
        toggle.textContent = '\u2212';
        toggle.title = 'Collapse branch';
        if (spouseId) toggle.dataset.spouseId = spouseId;
        toggle.addEventListener('click', function() {
            var collapsed = childrenRow.classList.toggle('collapsed');
            toggle.textContent = collapsed ? '+' : '\u2212';
            toggle.title = collapsed ? 'Expand branch' : 'Collapse branch';
            if (typeof window._treeRedraw === 'function') window._treeRedraw();
        });
        family.appendChild(toggle);
    }

    // ── Children of primary couple ──
    addChildrenGroup(node.children, null);

    // ── Children of extra spouse pairs ──
    if (node.extraChildGroups) {
        node.extraChildGroups.forEach(function(g) {
            addChildrenGroup(g.children, String(g.spouseId));
        });
    }

    return family;
}

// ── SVG line helpers ──────────────────────────────────────────────────────────

function svgLine(svg, x1, y1, x2, y2, opts) {
    opts = opts || {};
    const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
    line.setAttribute('x1', x1); line.setAttribute('y1', y1);
    line.setAttribute('x2', x2); line.setAttribute('y2', y2);
    line.setAttribute('stroke',       opts.stroke || '#8899aa');
    line.setAttribute('stroke-width', opts.width  || 2);
    if (opts.dashed) line.setAttribute('stroke-dasharray', '6,4');
    svg.appendChild(line);
}

/**
 * Get an element's bounding rect relative to a reference container element
 * (not the viewport), accounting for any scroll on the container.
 */
function relRect(el, container) {
    const er = el.getBoundingClientRect();
    const cr = container.getBoundingClientRect();
    return {
        top:    er.top    - cr.top,
        left:   er.left   - cr.left,
        bottom: er.bottom - cr.top,
        right:  er.right  - cr.left,
        cx: (er.left + er.right)  / 2 - cr.left,
        cy: (er.top  + er.bottom) / 2 - cr.top,
        w: er.width,
        h: er.height,
    };
}

// ── Connector drawing ─────────────────────────────────────────────────────────

function drawFamilyConnectors(svg, wrap) {
    // Walk every .tree-family that has at least one direct .tree-children
    const families = Array.from(wrap.querySelectorAll('.tree-family'));

    families.forEach(function(fam) {
        const coupleEl = fam.querySelector(':scope > .tree-couple');
        if (!coupleEl) return;

        // Gather all direct .tree-children groups in this family
        var childrenEls = Array.from(fam.querySelectorAll(':scope > .tree-children'));
        if (!childrenEls.length) return;

        const cp = relRect(coupleEl, wrap);

        childrenEls.forEach(function(childrenEl) {
            // Determine which connector this child group drops from
            var spId = childrenEl.dataset.spouseId || null;
            var connectorEl;
            if (spId) {
                connectorEl = coupleEl.querySelector(':scope > .spouse-connector[data-spouse-id="' + spId + '"]');
            } else {
                connectorEl = coupleEl.querySelector(':scope > .spouse-connector[data-primary="1"]');
            }
            if (!connectorEl) connectorEl = coupleEl.querySelector(':scope > .spouse-connector');

            var startY = cp.bottom;
            var dropX  = cp.cx;
            if (connectorEl) {
                var cr = relRect(connectorEl, wrap);
                startY = cr.cy;
                dropX  = cr.cx;
            } else {
                // Single parent (no spouse connector): start the line from
                // just below the card's visible content, not its min-height bottom.
                var headCard = coupleEl.querySelector(':scope > .head-person') || coupleEl.querySelector(':scope > .person-card');
                if (headCard) {
                    dropX = relRect(headCard, wrap).cx;
                    var lastContent = headCard.querySelector('.person-years') || headCard.querySelector('.person-name');
                    if (lastContent) {
                        startY = relRect(lastContent, wrap).bottom + 6;
                    }
                }
            }

            // If collapsed, draw a short stub line and stop
            if (childrenEl.classList.contains('collapsed')) {
                var stubEnd = cp.bottom + 30;
                svgLine(svg, dropX, startY, dropX, stubEnd);
                return;
            }

            var childFams = Array.from(childrenEl.querySelectorAll(':scope > .tree-family'));
            if (!childFams.length) return;

            var childTops = childFams.map(function(cf) {
                var cc = cf.querySelector(':scope > .tree-couple');
                if (!cc) return null;
                // Target the head person (the actual child), not the first
                // card which may be an extra spouse on the left side.
                var card = cc.querySelector(':scope > .head-person') || cc.querySelector(':scope > .person-card');
                return relRect(card || cc, wrap);
            }).filter(Boolean);

            if (!childTops.length) return;

            var midY = childTops[0].top - 20;

            // Vertical line from connector to mid-point
            svgLine(svg, dropX, startY, dropX, midY);

            var xs = childTops.map(function(c) { return c.cx; });
            var minX = Math.min.apply(null, xs);
            var maxX = Math.max.apply(null, xs);

            // Horizontal bar spanning children
            if (minX < maxX) {
                svgLine(svg, minX, midY, maxX, midY);
            }

            // Extend bar to reach drop point if needed
            if (dropX < minX) svgLine(svg, dropX, midY, minX, midY);
            if (dropX > maxX) svgLine(svg, maxX, midY, dropX, midY);

            // Vertical lines down to each child
            childTops.forEach(function(ct) {
                svgLine(svg, ct.cx, midY, ct.cx, ct.top);
            });
        });
    });
}

function drawCousinLines(svg, wrap, pairs) {
    pairs.forEach(function(pair) {
        var id1 = pair[0], id2 = pair[1];
        var el1 = wrap.querySelector('.person-card[data-id="' + id1 + '"]');
        var el2 = wrap.querySelector('.person-card[data-id="' + id2 + '"]');
        if (!el1 || !el2) return;

        // Skip if either person is inside a collapsed branch
        if (el1.closest('.collapsed') || el2.closest('.collapsed')) return;

        var r1 = relRect(el1, wrap);
        var r2 = relRect(el2, wrap);

        // Use the bottom of the last visible content, not the card's min-height
        function contentBottom(card, r) {
            var last = card.querySelector('.person-years') || card.querySelector('.person-name');
            return last ? relRect(last, wrap).bottom + 6 : r.bottom;
        }
        var b1 = contentBottom(el1, r1);
        var b2 = contentBottom(el2, r2);

        // Route below whichever card sits lower, with extra clearance
        var baseline = Math.max(b1, b2) + 24;

        // Down from bottom of card 1 to baseline
        svgLine(svg, r1.cx, b1, r1.cx, baseline, { stroke: '#aab', width: 1.5, dashed: true });
        // Across at baseline
        svgLine(svg, r1.cx, baseline, r2.cx, baseline,  { stroke: '#aab', width: 1.5, dashed: true });
        // Up from baseline to bottom of card 2
        svgLine(svg, r2.cx, baseline, r2.cx, b2, { stroke: '#aab', width: 1.5, dashed: true });

        // "cousin" label centred on the horizontal segment
        var text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
        text.setAttribute('x', (r1.cx + r2.cx) / 2);
        text.setAttribute('y', baseline + 13);
        text.setAttribute('text-anchor', 'middle');
        text.setAttribute('fill', '#aab');
        text.setAttribute('font-size', '11');
        text.setAttribute('font-family', 'system-ui, sans-serif');
        text.textContent = _i18n.cousin || 'cousin';
        svg.appendChild(text);
    });
}

// ── Main render ───────────────────────────────────────────────────────────────

function renderTree(data, container) {
    container.innerHTML = '';
    _i18n = data.i18n || {};
    _urls = data.urls || {};

    // Outer scroll wrapper already exists in HTML as #tree-container;
    // we build the inner .tree-wrap here.
    const wrap = document.createElement('div');
    wrap.className = 'tree-wrap';

    const root = document.createElement('div');
    root.className = 'tree-root';

    var editMode = (data.editMode !== false);
    (data.rootNodes || []).forEach(function(node) {
        root.appendChild(buildFamilyNode(node, editMode));
    });

    wrap.appendChild(root);
    container.appendChild(wrap);

    // Create SVG overlay (drawn after layout)
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.style.cssText = [
        'position:absolute',
        'top:0', 'left:0',
        'width:100%', 'height:100%',
        'pointer-events:none',
        'overflow:visible',
    ].join(';');
    wrap.appendChild(svg);

    function redraw() {
        // Remove old toggle position styles before redraw
        var oldToggles = wrap.querySelectorAll('.branch-toggle');
        oldToggles.forEach(function(t) { t.style.left = ''; t.style.top = ''; });

        // Align each children group under its respective spouse connector
        alignChildrenGroups(wrap);

        svg.innerHTML = '';
        drawFamilyConnectors(svg, wrap);
        if (data.cousinPairs && data.cousinPairs.length) {
            drawCousinLines(svg, wrap, data.cousinPairs);
        }

        // Position toggle buttons on the vertical connector line
        positionToggleButtons(wrap);
    }

    // Expose redraw globally so toggle buttons can trigger it
    window._treeRedraw = redraw;

    // Draw after the browser has done layout
    requestAnimationFrame(function() {
        setTimeout(redraw, 0);
    });

    // Redraw on resize (debounced)
    var resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(redraw, 180);
    });
}

// ── Align children groups under their respective connectors ───────────────────

function alignChildrenGroups(wrap) {
    var families = Array.from(wrap.querySelectorAll('.tree-family'));
    families.forEach(function(fam) {
        var coupleEl = fam.querySelector(':scope > .tree-couple');
        if (!coupleEl) return;

        var childrenEls = Array.from(fam.querySelectorAll(':scope > .tree-children'));
        if (!childrenEls.length) return;

        childrenEls.forEach(function(childrenEl) {
            // Reset previous transform so measurements are clean
            childrenEl.style.transform = '';
        });

        // Force reflow so getBoundingClientRect is accurate after reset
        void wrap.offsetHeight;

        childrenEls.forEach(function(childrenEl) {
            var spId = childrenEl.dataset.spouseId || null;
            var connEl;
            if (spId) {
                connEl = coupleEl.querySelector(':scope > .spouse-connector[data-spouse-id="' + spId + '"]');
            } else {
                connEl = coupleEl.querySelector(':scope > .spouse-connector[data-primary="1"]');
            }
            if (!connEl) connEl = coupleEl.querySelector(':scope > .spouse-connector');
            if (!connEl) return;

            var connRect = connEl.getBoundingClientRect();
            var connCX = (connRect.left + connRect.right) / 2;

            var chRect = childrenEl.getBoundingClientRect();
            var chCX = (chRect.left + chRect.right) / 2;

            var offset = connCX - chCX;
            if (Math.abs(offset) > 1) {
                childrenEl.style.transform = 'translateX(' + offset + 'px)';
            }
        });
    });
}

// ── Toggle button positioning ─────────────────────────────────────────────────

function positionToggleButtons(wrap) {
    var families = Array.from(wrap.querySelectorAll('.tree-family'));
    families.forEach(function(fam) {
        var toggles = Array.from(fam.querySelectorAll(':scope > .branch-toggle'));
        if (!toggles.length) return;

        var coupleEl = fam.querySelector(':scope > .tree-couple');
        if (!coupleEl) return;

        var cp = relRect(coupleEl, fam);

        toggles.forEach(function(toggle) {
            // Find matching children element and connector
            var spId = toggle.dataset.spouseId || null;
            var childrenEl;
            var connEl;
            if (spId) {
                childrenEl = fam.querySelector(':scope > .tree-children[data-spouse-id="' + spId + '"]');
                connEl = coupleEl.querySelector(':scope > .spouse-connector[data-spouse-id="' + spId + '"]');
            } else {
                childrenEl = fam.querySelector(':scope > .tree-children:not([data-spouse-id])');
                connEl = coupleEl.querySelector(':scope > .spouse-connector[data-primary="1"]');
            }
            if (!connEl) connEl = coupleEl.querySelector(':scope > .spouse-connector');

            var connR = connEl ? relRect(connEl, fam) : null;
            var dropX = connR ? connR.cx : cp.cx;
            var startY = connR ? connR.cy : cp.bottom;
            var btnY;

            if (childrenEl && !childrenEl.classList.contains('collapsed')) {
                // Match the midY from drawFamilyConnectors: 20px above children top
                var ct = relRect(childrenEl, fam);
                var midY = ct.top - 20;
                // Center the button on the vertical line from connector to bracket
                btnY = startY + (midY - startY) / 2 - 10;
            } else {
                btnY = cp.bottom + 30 - 10;
            }

            toggle.style.position = 'absolute';
            toggle.style.left = (dropX - 10) + 'px';
            toggle.style.top = btnY + 'px';
        });
    });
}

// ── Bootstrap ─────────────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', function() {
    var container = document.getElementById('tree-container');
    var dataEl = document.getElementById('tree-data');
    if (container && dataEl) {
        var treeData = JSON.parse(dataEl.textContent);
        window.TREE_DATA = treeData;
        renderTree(treeData, container);
    }

    // Clean View toggle (tree.php)
    var cvBtn = document.getElementById('clean-view-btn');
    if (cvBtn) {
        var editLabel = cvBtn.dataset.editLabel || '\u270f\ufe0f Edit View';
        var cleanLabel = cvBtn.textContent;
        cvBtn.addEventListener('click', function() {
            document.body.classList.toggle('clean-view');
            cvBtn.textContent = document.body.classList.contains('clean-view')
                ? editLabel : cleanLabel;
        });
    }
});
