<?php
if (!defined('DB_PATH')) exit;
require_once __DIR__ . '/db_connect.php';

/**
 * Build the renderable tree structure for $treeId.
 *
 * Returns:
 *   rootNodes   – array of family-unit nodes (recursive, see below)
 *   cousinPairs – [[id1, id2], …]  for drawing dotted lines
 *   allPersons  – flat map  id => person row
 *
 * A family-unit node:
 *   person      – the "head" person row
 *   spouse      – the primary spouse row (or null)
 *   marriageInfo – ['year_married'=>…, 'year_separated'=>…] (or null)
 *   allSpouses  – [{id, name, year_married, year_separated}, …]
 *   children    – array of child family-unit nodes
 */
function build_tree_data(PDO $db, int $treeId): array {
    // ── Load ────────────────────────────────────────────────────────────────
    $stmt = $db->prepare('SELECT * FROM persons WHERE tree_id = ? ORDER BY id');
    $stmt->execute([$treeId]);
    $persons = $stmt->fetchAll();

    $personMap = [];
    foreach ($persons as $p) $personMap[(int)$p['id']] = $p;

    $stmt = $db->prepare('SELECT * FROM relationships WHERE tree_id = ?');
    $stmt->execute([$treeId]);
    $rels = $stmt->fetchAll();

    // ── Index ────────────────────────────────────────────────────────────────
    $parentOf  = [];   // parentId  → [childId, …]
    $childOf   = [];   // childId   → [parentId, …]
    $spouseOf  = [];   // personId  → [{partner_id, year_married, year_separated, rel_id}, …]
    $cousinPairs = [];

    foreach ($rels as $r) {
        $r1 = (int)$r['person1_id'];
        $r2 = (int)$r['person2_id'];
        switch ($r['relationship_type']) {
            case 'parent_child':
                $parentOf[$r1][] = $r2;
                $childOf[$r2][]  = $r1;
                break;
            case 'spouse':
                $entry = [
                    'partner_id'    => $r2,
                    'year_married'  => $r['year_married'],
                    'year_separated'=> $r['year_separated'],
                    'year_married_approx'  => $r['year_married_approx'] ?? 0,
                    'year_separated_approx'=> $r['year_separated_approx'] ?? 0,
                    'rel_id'        => (int)$r['id'],
                ];
                $spouseOf[$r1][] = $entry;
                $entry['partner_id'] = $r1;
                $spouseOf[$r2][] = $entry;
                break;
            case 'cousin':
                $cousinPairs[] = [$r1, $r2];
                break;
        }
    }

    // Only show cousin lines when at least one cousin has no parent in the tree
    $cousinPairs = array_values(array_filter($cousinPairs, function ($pair) use ($childOf) {
        return empty($childOf[$pair[0]]) || empty($childOf[$pair[1]]);
    }));

    // Primary spouse = most recent marriage (year_married DESC, rel_id DESC).
    // "Youngest spouse" interpreted as the most recently married partner.
    $getPrimarySpouseId = function(int $pid) use ($spouseOf): ?int {
        if (empty($spouseOf[$pid])) return null;
        $sp = $spouseOf[$pid];
        usort($sp, function ($a, $b) {
            $ya = $a['year_married']; $yb = $b['year_married'];
            if ($ya !== $yb) {
                if ($ya === null) return  1;
                if ($yb === null) return -1;
                return (int)$yb - (int)$ya;   // desc
            }
            return $b['rel_id'] - $a['rel_id'];
        });
        return (int)$sp[0]['partner_id'];
    };

    $processed = [];

    $buildNode = null;
    $buildNode = function (int $pid) use (
        &$buildNode, $personMap, &$processed,
        $getPrimarySpouseId, $spouseOf, $parentOf
    ): ?array {
        if (isset($processed[$pid]) || !isset($personMap[$pid])) return null;
        $processed[$pid] = true;

        $person = $personMap[$pid];

        // Primary spouse (mark as processed so it won't appear as its own root)
        $spId = $getPrimarySpouseId($pid);
        $spouse = $marriageInfo = null;
        if ($spId !== null && !isset($processed[$spId]) && isset($personMap[$spId])) {
            $processed[$spId] = true;
            $spouse = $personMap[$spId];
            foreach ($spouseOf[$pid] as $s) {
                if ((int)$s['partner_id'] === $spId) {
                    $marriageInfo = [
                        'year_married'   => $s['year_married'],
                        'year_separated' => $s['year_separated'],
                        'year_married_approx'  => $s['year_married_approx'] ?? 0,
                        'year_separated_approx'=> $s['year_separated_approx'] ?? 0,
                    ];
                    break;
                }
            }
        }

        // All spouse entries (for the edit form info list)
        $allSpouses = [];
        foreach ($spouseOf[$pid] ?? [] as $s) {
            $sid = (int)$s['partner_id'];
            if (isset($personMap[$sid])) {
                $allSpouses[] = [
                    'id'            => $sid,
                    'name'          => $personMap[$sid]['name'],
                    'year_married'  => $s['year_married'],
                    'year_separated'=> $s['year_separated'],
                    'year_married_approx'  => $s['year_married_approx'] ?? 0,
                    'year_separated_approx'=> $s['year_separated_approx'] ?? 0,
                ];
            }
        }

        // Additional spouses of the primary spouse (beyond the head person).
        // This covers the case where the primary spouse has multiple partners
        // but was absorbed into this node before their own node was built.
        $spouseAllSpouses = [];
        if ($spouse) {
            $spouseId = (int)$spouse['id'];
            foreach ($spouseOf[$spouseId] ?? [] as $s) {
                $sid = (int)$s['partner_id'];
                if ($sid === $pid) continue;
                if (isset($personMap[$sid])) {
                    $processed[$sid] = true;
                    $spouseAllSpouses[] = [
                        'id'            => $sid,
                        'name'          => $personMap[$sid]['name'],
                        'year_married'  => $s['year_married'],
                        'year_separated'=> $s['year_separated'],
                        'year_married_approx'  => $s['year_married_approx'] ?? 0,
                        'year_separated_approx'=> $s['year_separated_approx'] ?? 0,
                    ];
                }
            }
        }

        // ── Collect children, grouped by spouse pair ─────────────────────
        // Sort children by birth year (nulls last), then by id as tiebreaker.
        $sortChildren = function (array &$nodes): void {
            usort($nodes, function ($a, $b) {
                $ya = $a['person']['birth_year'] ?? null;
                $yb = $b['person']['birth_year'] ?? null;
                if ($ya === null && $yb === null) return 0;
                if ($ya === null) return 1;   // nulls last
                if ($yb === null) return -1;
                return $ya <=> $yb;
            });
        };

        // Build a set of extra-spouse IDs (head's extras + primary spouse's extras)
        // so we can assign children to the correct couple.
        $extraSpouseIds = [];
        foreach ($allSpouses as $sp) {
            $sid = (int)$sp['id'];
            if ($spouse && $sid === (int)$spouse['id']) continue;
            $extraSpouseIds[$sid] = true;
        }
        foreach ($spouseAllSpouses as $sp) {
            $extraSpouseIds[(int)$sp['id']] = true;
        }

        // For each child, check if they also have an extra spouse as a parent.
        // If so, they belong to that extra spouse's group, not the primary couple.
        $childToExtraSpouse = [];  // childId => extraSpouseId
        foreach ($extraSpouseIds as $esId => $_) {
            foreach ($parentOf[$esId] ?? [] as $cid) {
                $childToExtraSpouse[(int)$cid] = $esId;
            }
        }

        // Primary couple (head + primary spouse) — exclude children claimed by extra spouses
        $primaryChildIds = array_unique(array_merge(
            $parentOf[$pid] ?? [],
            $spouse ? ($parentOf[(int)$spouse['id']] ?? []) : []
        ));

        $children = [];
        foreach ($primaryChildIds as $cid) {
            $cid = (int)$cid;
            if (isset($childToExtraSpouse[$cid])) continue;  // belongs to an extra spouse group
            if (!isset($processed[$cid])) {
                $node = $buildNode($cid);
                if ($node !== null) $children[] = $node;
            }
        }
        $sortChildren($children);

        // Children of each additional spouse of the head person
        $extraChildGroups = [];
        foreach ($allSpouses as $sp) {
            $sid = (int)$sp['id'];
            if ($spouse && $sid === (int)$spouse['id']) continue;
            $processed[$sid] = true;
            $extraIds = $parentOf[$sid] ?? [];
            $group = [];
            foreach ($extraIds as $cid) {
                $cid = (int)$cid;
                if (!isset($processed[$cid])) {
                    $node = $buildNode($cid);
                    if ($node !== null) $group[] = $node;
                }
            }
            if (!empty($group)) {
                $sortChildren($group);
                $extraChildGroups[] = ['spouseId' => $sid, 'children' => $group];
            }
        }

        // Children of each additional spouse of the primary spouse
        foreach ($spouseAllSpouses as $sp) {
            $sid = (int)$sp['id'];
            $extraIds = $parentOf[$sid] ?? [];
            $group = [];
            foreach ($extraIds as $cid) {
                $cid = (int)$cid;
                if (!isset($processed[$cid])) {
                    $node = $buildNode($cid);
                    if ($node !== null) $group[] = $node;
                }
            }
            if (!empty($group)) {
                $sortChildren($group);
                $extraChildGroups[] = ['spouseId' => $sid, 'children' => $group];
            }
        }

        return compact('person', 'spouse', 'marriageInfo', 'allSpouses', 'spouseAllSpouses', 'extraChildGroups', 'children');
    };

    // Roots = persons with no parents in this tree.
    // Sort so that roots whose primary spouse HAS parents are processed last.
    // This prevents a married-in spouse from absorbing a child-of-another-root
    // before that root gets a chance to claim the child.
    $rootCandidates = [];
    foreach ($personMap as $pid => $_) {
        if (empty($childOf[$pid])) {
            $rootCandidates[] = $pid;
        }
    }
    usort($rootCandidates, function ($a, $b) use ($getPrimarySpouseId, $childOf) {
        $spA = $getPrimarySpouseId($a);
        $spB = $getPrimarySpouseId($b);
        $aSpHasParent = $spA && !empty($childOf[$spA]) ? 1 : 0;
        $bSpHasParent = $spB && !empty($childOf[$spB]) ? 1 : 0;
        if ($aSpHasParent !== $bSpHasParent) return $aSpHasParent - $bSpHasParent;
        return $a - $b;   // preserve ID order within each group
    });

    $rootNodes = [];
    foreach ($rootCandidates as $pid) {
        $node = $buildNode($pid);
        if ($node !== null) $rootNodes[] = $node;
    }
    // Catch any persons skipped because they were already processed as a spouse above
    // but whose own sub-tree wasn't built (shouldn't happen, but safety net)
    foreach ($personMap as $pid => $_) {
        if (!isset($processed[$pid])) {
            $node = $buildNode($pid);
            if ($node !== null) $rootNodes[] = $node;
        }
    }

    return [
        'rootNodes'   => $rootNodes,
        'cousinPairs' => $cousinPairs,
        'allPersons'  => $personMap,
    ];
}
