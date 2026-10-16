<?php
require_once __DIR__ . '/lib/ClientVersion.php';
$client_version = client_version();
$player_id   = $_COOKIE['player_id']   ?? null;
$player_name = $_COOKIE['player_name'] ?? null;
$room_id     = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', $_GET['room'] ?? ''));

if (!$player_id || !$player_name) {
    header('Location: /?redirect=' . urlencode($room_id));
    exit;
}
if (strlen($room_id) !== 6) {
    header('Location: /?error=' . urlencode('Invalid room link'));
    exit;
}

// Load card data for JS injection
$modules_raw   = json_decode(file_get_contents(__DIR__ . '/data/cards/modules.json'), true);
$modules_by_id = [];
foreach ($modules_raw as $m) {
    $modules_by_id[$m['id']] = [
        'name'                => $m['name'],
        'type'                => $m['type'],
        'effects'             => $m['effects'] ?? '',
        'tier'                => (int)$m['tier'],
        'hardpoints_offered'  => (int)($m['hardpoints_offered']  ?? 0),
        'hardpoints_required' => (int)($m['hardpoints_required'] ?? 0),
        'energy_min'          => isset($m['energy_min']) ? (int)$m['energy_min'] : null,
        'energy_max'          => isset($m['energy_max']) ? (int)$m['energy_max'] : null,
        'range'               => $m['range'] ?? null,
        'aoe'                 => $m['aoe']   ?? null,
        'parts'               => $m['parts'] ?? [],
    ];
}

$pilots_raw    = json_decode(file_get_contents(__DIR__ . '/data/cards/pilots.json'), true);
$pilots_by_id  = [];
foreach ($pilots_raw['pilots'] as $p) {
    $pilots_by_id[$p['id']] = $p['name'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MechaTag &mdash; <?= htmlspecialchars($room_id) ?></title>
<link rel="stylesheet" href="css/style.css?v=<?= $client_version ?>">
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>
</head>
<body>

<div class="room-shell" x-data="roomApp()" x-init="init()">

    <!-- Page out of date after a deploy -->
    <div class="reload-banner" x-show="staleClient" x-cloak>
        <span>A new version of MechaTag is available. Reload to get it; your game continues where it is.</span>
        <button class="btn-sm" @click="location.reload()">Reload</button>
    </div>

    <!-- Header -->
    <header class="room-header">
        <h1><a href="/">MechaTag</a></h1>
        <div class="room-code">
            <span>Room:</span>
            <strong x-text="roomId"></strong>
            <button class="btn-sm btn-ghost" @click="copyLink" x-text="copyLabel"></button>
        </div>
        <!-- Game score (fights won; first to 2 wins the game) -->
        <div class="room-score" x-show="phase !== 'lobby'" title="Fights won. First team to 2 wins the game.">
            <span class="room-score-round"
                  x-text="roomState?.game_winner ? 'Game over' : 'Round ' + (roomState?.game_round ?? 1)"></span>
            <span class="room-score-team team-1" :class="{ mine: myTeam === 1 }"
                  x-text="myTeam === 1 ? 'Team 1 (you)' : 'Team 1'"></span>
            <span class="room-score-num team-1" x-text="roomState?.victories?.['1'] ?? 0"></span>
            <span class="room-score-sep">–</span>
            <span class="room-score-num team-2" x-text="roomState?.victories?.['2'] ?? 0"></span>
            <span class="room-score-team team-2" :class="{ mine: myTeam === 2 }"
                  x-text="myTeam === 2 ? 'Team 2 (you)' : 'Team 2'"></span>
        </div>
        <div class="room-player">
            Playing as <strong><?= htmlspecialchars($player_name) ?></strong>
            &mdash; <a href="api/logout.php">logout</a>
        </div>
    </header>

    <div class="room-body">

        <!-- ── Players sidebar ───────────────────────────────── -->
        <aside class="players-panel">
            <div class="panel-heading">Teams</div>

            <!-- Team 1 -->
            <div class="team-block team-1">
                <h3>Team 1</h3>
                <ul class="player-list">
                    <template x-for="p in playersOnTeam(1)" :key="p.id">
                        <li :class="{ offline: !p.online }">
                            <span x-text="p.name"></span>
                            <span x-show="p.is_me" class="badge badge-you">you</span>
                            <span x-show="!p.online" class="badge badge-off">away</span>
                        </li>
                    </template>
                    <li x-show="playersOnTeam(1).length === 0" class="empty-slot">empty</li>
                </ul>
                <button class="btn-sm btn-ghost"
                    x-show="phase === 'lobby' && myTeam !== 1"
                    @click="setTeam(1)">Join Team 1</button>
                <button class="btn-sm btn-danger"
                    x-show="phase === 'lobby' && myTeam === 1"
                    @click="setTeam(null)">Leave</button>
            </div>

            <!-- Team 2 -->
            <div class="team-block team-2">
                <h3>Team 2</h3>
                <ul class="player-list">
                    <template x-for="p in playersOnTeam(2)" :key="p.id">
                        <li :class="{ offline: !p.online }">
                            <span x-text="p.name"></span>
                            <span x-show="p.is_me" class="badge badge-you">you</span>
                            <span x-show="!p.online" class="badge badge-off">away</span>
                        </li>
                    </template>
                    <li x-show="playersOnTeam(2).length === 0" class="empty-slot">empty</li>
                </ul>
                <button class="btn-sm btn-ghost"
                    x-show="phase === 'lobby' && myTeam !== 2"
                    @click="setTeam(2)">Join Team 2</button>
                <button class="btn-sm btn-danger"
                    x-show="phase === 'lobby' && myTeam === 2"
                    @click="setTeam(null)">Leave</button>
            </div>

            <!-- Unassigned -->
            <div class="unassigned-block" x-show="unassigned.length > 0">
                <div class="panel-heading">Unassigned</div>
                <ul class="player-list">
                    <template x-for="p in unassigned" :key="p.id">
                        <li :class="{ offline: !p.online }">
                            <span x-text="p.name"></span>
                            <span x-show="p.is_me" class="badge badge-you">you</span>
                        </li>
                    </template>
                </ul>
            </div>

            <!-- Lobby actions -->
            <div class="sidebar-actions" x-show="phase === 'lobby'">
                <button class="btn-ghost" @click="randomizeTeams">Randomize Teams</button>
                <button class="btn-start" x-show="canStart" @click="startGame">Start Game</button>
            </div>

            <!-- Draft phase info -->
            <div class="sidebar-actions" x-show="phase === 'draft'">
                <div class="phase-label">Draft Phase</div>
                <div class="draft-round-label"
                     x-text="'Round ' + (draftState?.game_round ?? 1) + ' draft · Hand ' + (draftState?.round ?? '?') + ' · ' + (draftState?.step === 'initial' ? 'Pick from hand' : 'Pick passed cards')">
                </div>
            </div>

            <!-- Config phase info -->
            <div class="sidebar-actions" x-show="phase === 'config'">
                <div class="phase-label">Config Phase</div>
                <div class="draft-round-label">Assign cards to your mechs</div>
            </div>

            <!-- Fight phase info -->
            <div class="sidebar-actions" x-show="phase === 'fight'">
                <div class="phase-label">Fight Phase</div>
                <div class="draft-round-label" x-text="'Fight Deck: ' + (fightState?.deck_count ?? 0) + ' cards'"></div>
            </div>
        </aside>

        <!-- ── Main area ──────────────────────────────────────── -->
        <div class="main-area" :class="{ 'draft-mode': phase === 'draft', 'config-mode': phase === 'config', 'fight-mode': phase === 'fight' }">

            <!-- Draft panel (visible during draft phase) -->
            <div class="draft-panel" x-show="phase === 'draft'">

                <!-- Readiness banner -->
                <div class="draft-status-bar">
                    <span class="readiness-badge"
                          :class="draftState?.my_team_ready ? 'ready' : 'waiting'"
                          x-text="draftState?.my_team_ready ? 'Your team: ready' : 'Your team: choosing...'">
                    </span>
                    <span class="readiness-badge"
                          :class="draftState?.opp_team_ready ? 'ready' : 'waiting'"
                          x-text="draftState?.opp_team_ready ? 'Opponents: ready' : 'Opponents: choosing...'">
                    </span>
                </div>

                <!-- Hand -->
                <div class="draft-section-label">
                    Your Hand
                    <span class="pick-hint" x-show="!draftState?.my_team_ready">
                        — pick <strong x-text="picksNeeded"></strong> as a team
                    </span>
                </div>
                <div class="card-grid">
                    <template x-for="(cardId, idx) in (draftState?.hand ?? [])" :key="idx">
                        <div class="module-card"
                             :class="{ ...cardClass(idx), ['tier-card-' + (cardInfo(cardId)?.tier ?? '')]: true }"
                             @click="draftPick(idx)">
                            <div class="card-top">
                                <div class="card-name" x-text="cardInfo(cardId)?.name ?? cardId"></div>
                                <div class="card-hp" x-html="hardpointIcons(cardInfo(cardId))"></div>
                            </div>
                            <div class="card-meta">
                                <span class="card-type-badge" :class="'type-' + (cardInfo(cardId)?.type ?? '')"
                                      x-text="cardInfo(cardId)?.type"></span>
                                <span class="card-tier" :class="'tier-' + (cardInfo(cardId)?.tier ?? '')" x-text="'T' + (cardInfo(cardId)?.tier ?? '')"></span>
                            </div>
                            <div class="card-effects" x-text="cardInfo(cardId)?.effects"></div>
                            <div class="card-aoe" x-show="cardInfo(cardId)?.aoe"
                                 x-text="cardInfo(cardId)?.aoe === 'tile' ? '+entire tile' : '+neighboring tiles'"></div>
                            <div class="card-energy" x-show="cardInfo(cardId)?.energy_max"
                                 x-html="energySquaresHtml(cardInfo(cardId))"></div>
                            <div class="card-range" x-show="cardInfo(cardId)?.range"
                                 x-html="rangeHtml(cardInfo(cardId))"></div>
                            <div class="card-parts"
                                 x-text="(cardInfo(cardId)?.parts ?? []).join(' · ').toUpperCase()"></div>
                            <div class="card-pick-label" x-show="isMyPick(idx)">Selected</div>
                            <div class="card-pick-label teammate" x-show="isTeammatePick(idx)">Teammate</div>
                        </div>
                    </template>
                </div>

                <!-- Collected -->
                <div class="draft-section-label" x-show="(draftState?.collected?.mine?.length ?? 0) > 0">
                    Collected (<span x-text="draftState?.collected?.mine?.length ?? 0"></span>)
                </div>
                <div class="card-grid card-grid-sm" x-show="(draftState?.collected?.mine?.length ?? 0) > 0">
                    <template x-for="(cardId, idx) in (draftState?.collected?.mine ?? [])" :key="idx">
                        <div class="module-card collected" :class="'tier-card-' + (cardInfo(cardId)?.tier ?? '')">
                            <div class="card-top">
                                <div class="card-name" x-text="cardInfo(cardId)?.name ?? cardId"></div>
                                <div class="card-hp" x-html="hardpointIcons(cardInfo(cardId))"></div>
                            </div>
                            <div class="card-meta">
                                <span class="card-type-badge" :class="'type-' + (cardInfo(cardId)?.type ?? '')"
                                      x-text="cardInfo(cardId)?.type"></span>
                                <span class="card-tier" :class="'tier-' + (cardInfo(cardId)?.tier ?? '')" x-text="'T' + (cardInfo(cardId)?.tier ?? '')"></span>
                            </div>
                            <div class="card-effects" x-text="cardInfo(cardId)?.effects"></div>
                            <div class="card-aoe" x-show="cardInfo(cardId)?.aoe"
                                 x-text="cardInfo(cardId)?.aoe === 'tile' ? '+entire tile' : '+neighboring tiles'"></div>
                            <div class="card-energy" x-show="cardInfo(cardId)?.energy_max"
                                 x-html="energySquaresHtml(cardInfo(cardId))"></div>
                            <div class="card-range" x-show="cardInfo(cardId)?.range"
                                 x-html="rangeHtml(cardInfo(cardId))"></div>
                            <div class="card-parts"
                                 x-text="(cardInfo(cardId)?.parts ?? []).join(' · ').toUpperCase()"></div>
                        </div>
                    </template>
                </div>

                <!-- Mech preview (draft phase): own team, then the opponent's (layouts are public) -->
                <template x-for="side in draftMechSides()" :key="side.team">
                    <div class="draft-mech-side" :class="[side.mine ? 'mine' : 'opponent', 'team-' + side.team]">
                        <div class="draft-section-label draft-side-label" style="margin-top:0.5rem">
                            <span x-show="side.mine">Your Mechs</span>
                            <span x-show="!side.mine" class="opp-badge">Opponent</span>
                            <span x-show="!side.mine" x-text="'Team ' + side.team + ' mechs'"></span>
                        </div>
                        <div class="mech-previews">
                            <template x-for="mechIdx in [0, 1]" :key="mechIdx">
                                <div class="mech-outline">
                                    <img :src="'images/ulysses' + capFirst(mechPilotColor(mechIdx, side.team)) + '.png'"
                                         class="mech-img" alt="Mech">
                                    <div class="mech-label" x-text="mechLabel(mechIdx, side.team)"></div>
                                    <!-- Loadout kept from the last round -->
                                    <template x-if="side.mechs[mechIdx]">
                                        <div class="fight-loadout draft-loadout">
                                            <template x-for="slot in ['head','torso','r_arm','l_arm','legs']" :key="slot">
                                                <div class="fight-slot"
                                                     x-show="side.mechs[mechIdx].slots?.[slot] || (side.mechs[mechIdx].equipment?.[slot]?.length ?? 0) > 0">
                                                    <span class="slot-label" x-text="slotLabel(slot)"></span>
                                                    <span class="fight-module" :class="{ 'is-default': !side.mechs[mechIdx].slots?.[slot] }"
                                                          @mouseenter="showCardPreview(side.mechs[mechIdx].slots?.[slot], $event)"
                                                          @mouseleave="hideCardPreview()"
                                                          x-text="side.mechs[mechIdx].slots?.[slot]
                                                              ? (cardInfo(side.mechs[mechIdx].slots[slot])?.name ?? side.mechs[mechIdx].slots[slot])
                                                              : (slot === 'head' ? '—' : 'Default')"></span>
                                                    <template x-for="(eqId, ei) in (side.mechs[mechIdx].equipment?.[slot] ?? [])" :key="ei">
                                                        <span class="fight-module is-equip"
                                                              @mouseenter="showCardPreview(eqId, $event)"
                                                              @mouseleave="hideCardPreview()"
                                                              x-text="cardInfo(eqId)?.name ?? eqId"></span>
                                                    </template>
                                                </div>
                                            </template>
                                            <div class="draft-loadout-empty"
                                                 x-show="!Object.values(side.mechs[mechIdx].slots ?? {}).some(Boolean)
                                                      && !Object.values(side.mechs[mechIdx].equipment ?? {}).some(e => e.length)">
                                                No modules equipped
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>

                        <!-- Modules kept from earlier rounds but not on a mech -->
                        <div class="draft-section-label" x-show="side.mine && (draftState?.unassigned?.length ?? 0) > 0">
                            Your Unequipped Cards (<span x-text="draftState?.unassigned?.length ?? 0"></span>)
                        </div>
                        <div class="card-grid card-grid-sm" x-show="side.mine && (draftState?.unassigned?.length ?? 0) > 0">
                            <template x-for="(cardId, idx) in (draftState?.unassigned ?? [])" :key="idx">
                                <div class="module-card collected" :class="'tier-card-' + (cardInfo(cardId)?.tier ?? '')">
                                    <div class="card-top">
                                        <div class="card-name" x-text="cardInfo(cardId)?.name ?? cardId"></div>
                                        <div class="card-hp" x-html="hardpointIcons(cardInfo(cardId))"></div>
                                    </div>
                                    <div class="card-meta">
                                        <span class="card-type-badge" :class="'type-' + (cardInfo(cardId)?.type ?? '')"
                                              x-text="cardInfo(cardId)?.type"></span>
                                        <span class="card-tier" :class="'tier-' + (cardInfo(cardId)?.tier ?? '')" x-text="'T' + (cardInfo(cardId)?.tier ?? '')"></span>
                                    </div>
                                    <div class="card-effects" x-text="cardInfo(cardId)?.effects"></div>
                                    <div class="card-aoe" x-show="cardInfo(cardId)?.aoe"
                                         x-text="cardInfo(cardId)?.aoe === 'tile' ? '+entire tile' : '+neighboring tiles'"></div>
                                    <div class="card-energy" x-show="cardInfo(cardId)?.energy_max"
                                         x-html="energySquaresHtml(cardInfo(cardId))"></div>
                                    <div class="card-range" x-show="cardInfo(cardId)?.range"
                                         x-html="rangeHtml(cardInfo(cardId))"></div>
                                    <div class="card-parts"
                                         x-text="(cardInfo(cardId)?.parts ?? []).join(' · ').toUpperCase()"></div>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>

            </div><!-- /.draft-panel -->

            <!-- Config panel (visible during config phase) -->
            <div class="config-panel" x-show="phase === 'config'">

                <div class="draft-status-bar">
                    <div class="phase-label" style="font-size:0.9rem">Mech Configuration</div>
                    <div class="config-hint" x-show="!selectedCard">Select a card from the pool, then click a slot to assign it</div>
                    <div class="config-hint selected" x-show="selectedCard">
                        <strong x-text="cardInfo(selectedCard)?.name"></strong> selected — click a slot or click again to deselect
                    </div>
                </div>

                <div class="config-notice" x-show="configState?.notice" x-text="configState?.notice"></div>

                <!-- Available cards pool -->
                <div class="draft-section-label">
                    Available Cards (<span x-text="configState?.available?.length ?? 0"></span>)
                </div>
                <div class="card-grid card-grid-sm" x-show="(configState?.available?.length ?? 0) > 0">
                    <template x-for="(cardId, idx) in (configState?.available ?? [])" :key="idx">
                        <div class="module-card"
                             :class="{ 'card-mine': selectedCard === cardId }"
                             @click="configSelectCard(cardId)">
                            <div class="card-top">
                                <div class="card-name" x-text="cardInfo(cardId)?.name ?? cardId"></div>
                                <div class="card-hp" x-html="hardpointIcons(cardInfo(cardId))"></div>
                            </div>
                            <div class="card-meta">
                                <span class="card-type-badge" :class="'type-' + (cardInfo(cardId)?.type ?? '')"
                                      x-text="cardInfo(cardId)?.type"></span>
                                <span class="card-tier" :class="'tier-' + (cardInfo(cardId)?.tier ?? '')" x-text="'T' + (cardInfo(cardId)?.tier ?? '')"></span>
                            </div>
                            <div class="card-effects" x-text="cardInfo(cardId)?.effects"></div>
                            <div class="card-aoe" x-show="cardInfo(cardId)?.aoe"
                                 x-text="cardInfo(cardId)?.aoe === 'tile' ? '+entire tile' : '+neighboring tiles'"></div>
                            <div class="card-energy" x-show="cardInfo(cardId)?.energy_max"
                                 x-html="energySquaresHtml(cardInfo(cardId))"></div>
                            <div class="card-range" x-show="cardInfo(cardId)?.range"
                                 x-html="rangeHtml(cardInfo(cardId))"></div>
                            <div class="card-parts"
                                 x-text="(cardInfo(cardId)?.parts ?? []).join(' · ').toUpperCase()"></div>
                        </div>
                    </template>
                </div>
                <div class="config-empty-pool" x-show="(configState?.available?.length ?? 0) === 0 && configState">All cards assigned</div>

                <!-- Mechs -->
                <div class="draft-section-label" style="margin-top:0.25rem">Your Mechs</div>
                <div class="mechs-row">
                    <template x-for="(mech, mechIdx) in (configState?.mechs ?? [])" :key="mechIdx">
                        <div class="mech-config-card">
                            <div class="mech-config-header">
                                <img :src="'images/ulysses' + capFirst(mechPilotColor(mechIdx)) + '.png'"
                                     class="mech-img-sm" alt="Mech">
                                <div class="mech-config-info">
                                    <div class="mech-title" x-text="mechLabel(mechIdx)"></div>
                                </div>
                            </div>

                            <!-- Part slots + per-slot equipment -->
                            <div class="mech-slots">
                                <template x-for="slot in ['head','torso','r_arm','l_arm','legs']" :key="slot">
                                    <div class="mech-slot-group">
                                        <!-- Part row -->
                                        <div class="mech-slot"
                                             :class="slotClass(mech, slot)"
                                             @click="hideCardPreview(); configPartClick(mechIdx, slot, mech.slots[slot])">
                                            <div class="slot-label" x-text="slotLabel(slot)"></div>
                                            <div class="slot-content"
                                                 @mouseenter="showCardPreview(mech.slots[slot], $event)"
                                                 @mouseleave="hideCardPreview()"
                                                 x-text="mech.slots[slot] ? (cardInfo(mech.slots[slot])?.name ?? mech.slots[slot]) : slotDefault(slot)">
                                            </div>
                                            <span class="card-tier" :class="'tier-' + (cardInfo(mech.slots[slot])?.tier ?? '')" x-show="cardInfo(mech.slots[slot])?.tier"
                                                  x-text="'T' + (cardInfo(mech.slots[slot])?.tier ?? '')"></span>
                                            <div class="slot-hardp"
                                                 x-show="(mech.slot_hp?.[slot]?.available ?? 0) > 0"
                                                 :class="{ 'no-free': slotHardpFree(mech, slot) === 0 }"
                                                 x-text="slotHardpFree(mech, slot) + ' hardp'">
                                            </div>
                                        </div>
                                        <!-- Equipment attached to this slot -->
                                        <div class="slot-equip-row"
                                             x-show="(mech.slot_hp?.[slot]?.available ?? 0) > 0 || (mech.equipment?.[slot]?.length ?? 0) > 0">
                                            <template x-for="(eqId, ei) in (mech.equipment?.[slot] ?? [])" :key="ei">
                                                <div class="equip-tag"
                                                     @mouseenter="showCardPreview(eqId, $event)"
                                                     @mouseleave="hideCardPreview()"
                                                     @click.stop="hideCardPreview(); configAssign(mechIdx, slot, eqId)">
                                                    <span x-text="cardInfo(eqId)?.name ?? eqId"></span>
                                                    <span class="card-tier" :class="'tier-' + (cardInfo(eqId)?.tier ?? '')" x-text="'T' + (cardInfo(eqId)?.tier ?? '')"></span>
                                                    <span class="equip-remove">×</span>
                                                </div>
                                            </template>
                                            <div class="equip-drop-zone"
                                                 x-show="selectedCard && cardInfo(selectedCard)?.type === 'equipment' && slotAcceptsEquip(slot, cardInfo(selectedCard), mech)"
                                                 @click.stop="configEquipAdd(mechIdx, slot)">
                                                + Add
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>

                <!-- Readiness -->
                <div class="draft-status-bar" style="margin-top:0.25rem">
                    <span class="readiness-badge"
                          :class="configState?.my_team_ready ? 'ready' : 'waiting'"
                          x-text="configState?.my_team_ready ? 'Your team: ready' : 'Your team: configuring'">
                    </span>
                    <span class="readiness-badge"
                          :class="configState?.opp_team_ready ? 'ready' : 'waiting'"
                          x-text="configState?.opp_team_ready ? 'Opponents: ready' : 'Opponents: configuring'">
                    </span>
                    <button class="btn-sm"
                            :class="configState?.my_team_ready ? 'btn-danger' : 'btn-start'"
                            style="margin-left:auto"
                            @click="configToggleReady">
                        <span x-text="configState?.my_team_ready ? 'Unready' : 'Done — Start Fight'"></span>
                    </button>
                </div>

            </div><!-- /.config-panel -->

            <!-- Fight panel -->
            <div class="fight-panel" x-show="phase === 'fight'">

                <!-- Deck controls + current card -->
                <div class="fight-top">
                    <div class="fight-deck-row">
                        <div class="fight-deck-stack" :class="{ empty: (fightState?.deck_count ?? 0) === 0 }">
                            <div class="fight-deck-label">Deck</div>
                            <div class="fight-deck-count" x-text="fightState?.deck_count ?? 0"></div>
                        </div>
                        <button class="btn-start fight-flip-btn"
                                :disabled="!canFlip"
                                @click="fightFlip">
                            Flip Card
                        </button>
                    </div>

                    <!-- Card being resolved: large, so it can't be confused with the discards -->
                    <div class="fight-current">
                        <div class="fight-current-label">Now resolving</div>
                        <div class="fight-card fight-card-current" x-show="fightState?.current"
                             :class="{ ['pilot-bg-' + (fightState?.current?.pilot ?? '')]: true, flippable: canFlip }"
                             :title="canFlip ? 'Click to flip the next card' : ''"
                             @click="canFlip && fightFlip()">
                            <div class="fight-card-pilot"
                                 x-text="pilotName(fightState?.current?.pilot)"></div>
                            <div class="fight-card-name"
                                 x-text="fightState?.current?.name"></div>
                            <div class="fight-card-effect"
                                 x-text="fightState?.current?.effect"></div>
                        </div>
                        <div class="fight-card fight-card-current fight-card-none" x-show="!fightState?.current"
                             :class="{ flippable: canFlip }"
                             :title="canFlip ? 'Click to flip the first card' : ''"
                             @click="canFlip && fightFlip()">
                            <div class="fight-card-name">No card yet</div>
                            <div class="fight-card-effect">Flip to start</div>
                        </div>
                    </div>

                    <!-- One discard pile per pilot, showing that pilot's last card -->
                    <div class="fight-discards">
                        <div class="fight-current-label">Discards</div>
                        <div class="fight-discard-piles">
                            <template x-for="pile in (fightState?.discards ?? [])" :key="pile.pilot">
                                <div class="fight-card fight-card-discard"
                                     :class="{ ['pilot-bg-' + pile.pilot]: true, empty: !pile.top }">
                                    <div class="fight-card-pilot">
                                        <span x-text="pilotName(pile.pilot)"></span>
                                        <span class="fight-discard-count" x-text="'· ' + pile.count"></span>
                                    </div>
                                    <div class="fight-card-name" x-text="pile.top?.name ?? '—'"></div>
                                    <div class="fight-card-effect" x-text="pile.top?.effect ?? ''"></div>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>

                <!-- Fight result -->
                <div class="fight-result" x-show="fightState?.result"
                     :class="fightState?.result?.winner ? 'team-' + fightState.result.winner : 'draw'">
                    <div class="fight-result-title"
                         x-text="roomState?.game_winner
                             ? '🏆 Team ' + roomState.game_winner + ' wins the game!'
                             : (fightState?.result?.winner ? 'Team ' + fightState.result.winner + ' wins the fight!' : 'The fight is a draw')"></div>
                    <div class="fight-result-detail" x-text="fightResultDetail()"></div>
                    <div class="fight-result-score"
                         x-text="'Score: Team 1 ' + (roomState?.victories?.['1'] ?? 0) + ' – ' + (roomState?.victories?.['2'] ?? 0) + ' Team 2'"></div>
                    <button class="fight-next-round" x-show="!roomState?.game_winner" @click="nextRound">
                        Start Round <span x-text="(roomState?.game_round ?? 1) + 1"></span>
                    </button>
                </div>

                <!-- Pending choice: charge modules (CORE / generator) -->
                <div class="fight-choice" x-show="fightState?.pending">
                    <!-- Defend: the attacked team picks shields (each costs its energy) -->
                    <template x-if="fightState?.pending?.kind === 'defend' && myTeam === Number(fightState.pending.team)">
                        <div>
                            <div class="fight-choice-title">
                                <span x-text="fightState.pending.hit.attacker + ' attacks ' + fightState.mechs[fightState.pending.team][fightState.pending.mech].pilot_name
                                    + ' with ' + fightState.pending.hit.weapon + ': ' + fightState.pending.hit.dmg + ' ' + fightState.pending.hit.atk_type + ' damage'"></span>
                            </div>
                            <div class="fight-choice-sub" x-show="fightState.pending.free_def > 0"
                                 x-text="'Already blocking ' + fightState.pending.free_def + ' with ' + fightState.pending.free_names.join(', ') + ' (free)'"></div>
                            <div class="fight-choice-sub">Choose whether to use shields. Each one spends its energy cost.</div>
                            <div class="fight-choice-options">
                                <template x-for="id in fightState.pending.options" :key="id">
                                    <button class="fight-module fight-choice-opt"
                                            :class="{ picked: chargePicks.includes(id) }"
                                            @click="toggleChargePick(id)"
                                            @mouseenter="showCardPreview(id, $event)"
                                            @mouseleave="hideCardPreview()">
                                        <span x-text="(cardInfo(id)?.name ?? id) + ' · DEF ' + fightState.pending.details[id].def + ' · costs ' + fightState.pending.details[id].cost"></span>
                                        <span class="fight-energy"
                                              x-html="fightEnergyHtml(fightState.mechs[fightState.pending.team][fightState.pending.mech], id)"></span>
                                    </button>
                                </template>
                            </div>
                            <div class="fight-choice-sub" x-text="'Damage taken: ' + defendDamagePreview()"></div>
                            <div class="fight-choice-actions">
                                <button class="btn-sm" @click="fightChoose()" :disabled="chargePicks.length === 0">Spend energy &amp; block</button>
                                <button class="btn-sm btn-ghost" @click="fightChoose(true)">Take the hit</button>
                            </div>
                        </div>
                    </template>
                    <!-- Move: pick direction and distance (0..N) -->
                    <template x-if="fightState?.pending?.kind === 'move' && myTeam === Number(fightState.pending.team)">
                        <div>
                            <div class="fight-choice-title"
                                 x-text="fightState.pending.source + ': choose where ' + fightState.mechs[fightState.pending.team][fightState.pending.mech].pilot_name + ' moves'"></div>
                            <div class="fight-choice-options">
                                <template x-for="d in fightState.pending.options" :key="d">
                                    <button class="fight-module fight-choice-opt"
                                            :class="{ picked: chargePicks.includes(d) }"
                                            @click="chargePicks = [d]"
                                            x-text="fightState.pending.labels[d]"></button>
                                </template>
                            </div>
                            <div class="fight-choice-actions">
                                <button class="btn-sm" @click="fightChoose()" :disabled="chargePicks.length === 0">Move</button>
                            </div>
                        </div>
                    </template>
                    <!-- Lock: pick an enemy to lock onto, or which held lock to spend on a LOCK-1 weapon -->
                    <template x-if="(fightState?.pending?.kind === 'lock' || fightState?.pending?.kind === 'spend_lock') && myTeam === Number(fightState.pending.team)">
                        <div>
                            <div class="fight-choice-title"
                                 x-text="fightState.pending.kind === 'lock'
                                     ? fightState.pending.source + ': choose an enemy to lock onto'
                                     : fightState.pending.source + ': choose which lock to spend'"></div>
                            <div class="fight-choice-options">
                                <template x-for="pilot in fightState.pending.options" :key="pilot">
                                    <button class="fight-module fight-choice-opt"
                                            :class="{ picked: chargePicks.includes(pilot) }"
                                            @click="chargePicks = chargePicks.includes(pilot) ? [] : [pilot]">
                                        <span class="pilot-dot" :class="'pilot-' + pilot" style="width:10px;height:10px"></span>
                                        <span x-text="'🎯 ' + pilotName(pilot) + ' · tile ' + (enemyPos(pilot) + 1)"></span>
                                    </button>
                                </template>
                            </div>
                            <div class="fight-choice-actions">
                                <button class="btn-sm" @click="fightChoose()" :disabled="chargePicks.length === 0"
                                        x-text="fightState.pending.kind === 'lock' ? 'Lock on' : 'Spend lock & fire'"></button>
                                <button class="btn-sm btn-ghost" @click="fightChoose(true)"
                                        x-text="fightState.pending.kind === 'lock' ? 'Skip' : 'Hold fire'"></button>
                            </div>
                        </div>
                    </template>
                    <template x-if="fightState?.pending?.kind === 'charge' && myTeam === Number(fightState.pending.team)">
                        <div>
                            <div class="fight-choice-title">
                                <span x-text="fightState.pending.source"></span>:
                                choose up to <span x-text="fightState.pending.count"></span>
                                module<span x-show="fightState.pending.count > 1">s</span>
                                to get +1 energy
                            </div>
                            <div class="fight-choice-options">
                                <template x-for="id in fightState.pending.options" :key="id">
                                    <button class="fight-module fight-choice-opt"
                                            :class="{ picked: chargePicks.includes(id) }"
                                            :disabled="!chargePicks.includes(id) && chargePicks.length >= fightState.pending.count"
                                            @click="toggleChargePick(id)"
                                            @mouseenter="showCardPreview(id, $event)"
                                            @mouseleave="hideCardPreview()">
                                        <span x-text="cardInfo(id)?.name ?? id"></span>
                                        <span class="fight-energy"
                                              x-html="fightEnergyHtml(fightState.mechs[fightState.pending.team][fightState.pending.mech], id)"></span>
                                    </button>
                                </template>
                            </div>
                            <div class="fight-choice-actions">
                                <button class="btn-sm" @click="fightChoose()" :disabled="chargePicks.length === 0">Charge</button>
                                <button class="btn-sm btn-ghost" @click="fightChoose(true)">Skip</button>
                            </div>
                        </div>
                    </template>
                    <div class="fight-choice-wait"
                         x-show="fightState?.pending && myTeam !== Number(fightState?.pending?.team)"
                         x-text="'Waiting for Team ' + (fightState?.pending?.team ?? '') + ({ move: ' to choose where to move…', defend: ' to choose shields…', lock: ' to choose a lock target…', spend_lock: ' to choose which lock to spend…' }[fightState?.pending?.kind] ?? ' to choose modules to charge…')"></div>
                </div>

                <!-- Arena board -->
                <div class="arena-board"
                     x-init="new ResizeObserver(e => { boardW = e[0].contentRect.width; boardH = e[0].contentRect.height }).observe($el)">
                    <template x-for="tile in 6" :key="tile">
                        <div class="arena-tile" :class="{ 'arena-divider': tile === 4 }">
                            <div class="arena-tile-num" x-text="tile"></div>
                        </div>
                    </template>
                    <!-- Tokens sit in a layer over the tiles so a move can slide them across -->
                    <div class="arena-token-layer">
                        <template x-for="t in boardTokens" :key="t.key">
                            <span class="arena-token"
                                  :class="{ ['pilot-' + t.pilot]: true, destroyed: t.destroyed, wrecked: t.wrecked, acting: t.pilot === fightState?.current?.pilot }"
                                  :style="{ left: ((t.pos + 0.5) * 100 / 6) + '%', top: (t.y * 100) + '%', '--dx': t.dx + 'px' }"
                                  :title="t.name"></span>
                        </template>
                    </div>
                    <!-- Attack arrows for the card being resolved -->
                    <div class="arena-arrows">
                        <template x-for="a in boardArrows" :key="a.key">
                            <!-- one SVG per arrow (x-for can't loop inside an SVG); no viewBox, so units are board pixels -->
                            <svg class="arena-arrow" :class="{ pending: a.pending, blocked: a.damage === 0 && !a.pending }">
                                <defs>
                                    <marker :id="'ah-' + a.key" viewBox="0 0 10 10" refX="8" refY="5"
                                            markerWidth="6" markerHeight="6" orient="auto">
                                        <path d="M0,0 L10,5 L0,10 z" :fill="a.color"></path>
                                    </marker>
                                </defs>
                                <path class="arrow-line" :d="a.d" :stroke="a.color" :marker-end="'url(#ah-' + a.key + ')'" pathLength="1"></path>
                                <text :x="a.lx" :y="a.ly" x-text="a.label"></text>
                            </svg>
                        </template>
                    </div>
                </div>

                <!-- Mechs -->
                <div class="fight-mechs-row">
                    <template x-for="teamKey in ['1','2']" :key="teamKey">
                        <div class="fight-team-col" :class="'team-' + teamKey">
                            <div class="fight-team-label" x-text="'Team ' + teamKey"></div>
                            <template x-for="(mech, mi) in (fightState?.mechs?.[teamKey] ?? [])" :key="mi">
                                <div class="fight-mech" :class="{ destroyed: mech.hp <= 0 }">
                                    <div class="fight-mech-pilot">
                                        <span class="pilot-dot"
                                              :class="'pilot-' + mech.pilot"
                                              style="width:10px;height:10px"></span>
                                        <span x-text="mech.pilot_name"></span>
                                        <span class="fight-mech-def"
                                              x-show="mech.action_def"
                                              :title="'Usable against attacks until ' + mech.pilot_name + ' plays another card'"
                                              x-text="'🛡 DEF ' + (mech.action_def?.amount ?? '') + ' ' + (mech.action_def?.types ? mech.action_def.types.join('/') : 'any') + ' · ' + (mech.action_def?.card ?? '')"></span>
                                    </div>
                                    <div class="fight-locks" x-show="(mech.locks?.length ?? 0) > 0">
                                        <template x-for="pilot in (mech.locks ?? [])" :key="pilot">
                                            <span class="fight-lock" :class="'pilot-' + pilot"
                                                  :title="mech.pilot_name + ' has a target lock on ' + pilotName(pilot)"
                                                  x-text="'🎯 ' + pilotName(pilot)"></span>
                                        </template>
                                    </div>
                                    <div class="fight-hp-bar-wrap">
                                        <div class="fight-hp-bar"
                                             :style="'width:' + Math.max(0, mech.hp / mech.max_hp * 100) + '%'"
                                             :class="{ low: mech.hp / mech.max_hp < 0.35 }">
                                        </div>
                                    </div>
                                    <div class="fight-hp-text"
                                         x-text="mech.hp + ' / ' + mech.max_hp + ' HP'">
                                    </div>
                                    <!-- Loadout: part per slot + its equipment, with energy cubes -->
                                    <div class="fight-loadout">
                                        <template x-for="slot in ['head','torso','r_arm','l_arm','legs']" :key="slot">
                                            <div class="fight-slot"
                                                 x-show="mech.slots?.[slot] || (mech.equipment?.[slot]?.length ?? 0) > 0">
                                                <span class="slot-label" x-text="slotLabel(slot)"></span>
                                                <span class="fight-module" :class="{ 'is-default': !mech.slots?.[slot] }"
                                                      @mouseenter="showCardPreview(mech.slots?.[slot], $event)"
                                                      @mouseleave="hideCardPreview()">
                                                    <span x-text="mech.slots?.[slot] ? (cardInfo(mech.slots[slot])?.name ?? mech.slots[slot]) : (slot === 'head' ? '—' : 'Default')"></span>
                                                    <span class="fight-energy" x-show="mech.slots?.[slot]"
                                                          x-html="fightEnergyHtml(mech, mech.slots?.[slot])"></span>
                                                </span>
                                                <template x-for="(eqId, ei) in (mech.equipment?.[slot] ?? [])" :key="ei">
                                                    <span class="fight-module is-equip"
                                                          @mouseenter="showCardPreview(eqId, $event)"
                                                          @mouseleave="hideCardPreview()">
                                                        <span x-text="cardInfo(eqId)?.name ?? eqId"></span>
                                                        <span class="fight-energy" x-html="fightEnergyHtml(mech, eqId)"></span>
                                                    </span>
                                                </template>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>

                <!-- Fight log -->
                <div class="fight-log-label">Fight Log</div>
                <div class="fight-log" id="fight-log"></div>

            </div><!-- /.fight-panel -->

            <!-- Full-card preview on hover (draft, config and fight) -->
            <div class="module-card card-preview" x-show="preview.id" x-cloak
                 :style="{ left: preview.x + 'px', top: preview.y + 'px' }">
                <div class="card-top">
                    <div class="card-name" x-text="cardInfo(preview.id)?.name ?? preview.id"></div>
                    <div class="card-hp" x-html="hardpointIcons(cardInfo(preview.id))"></div>
                </div>
                <div class="card-meta">
                    <span class="card-type-badge" :class="'type-' + (cardInfo(preview.id)?.type ?? '')"
                          x-text="cardInfo(preview.id)?.type"></span>
                    <span class="card-tier" :class="'tier-' + (cardInfo(preview.id)?.tier ?? '')" x-text="'T' + (cardInfo(preview.id)?.tier ?? '')"></span>
                </div>
                <div class="card-effects" x-text="cardInfo(preview.id)?.effects"></div>
                <div class="card-aoe" x-show="cardInfo(preview.id)?.aoe"
                     x-text="cardInfo(preview.id)?.aoe === 'tile' ? '+entire tile' : '+neighboring tiles'"></div>
                <div class="card-energy" x-show="cardInfo(preview.id)?.energy_max"
                     x-html="energySquaresHtml(cardInfo(preview.id))"></div>
                <div class="card-range" x-show="cardInfo(preview.id)?.range"
                     x-html="rangeHtml(cardInfo(preview.id))"></div>
                <div class="card-parts"
                     x-text="(cardInfo(preview.id)?.parts ?? []).join(' · ').toUpperCase()"></div>
            </div>

            <!-- Chat panel -->
            <div class="chat-panel">
                <div class="chat-tabs">
                    <button :class="{ active: tab === 'global' }" @click="switchTab('global')">Global</button>
                    <button :class="{ active: tab === 'team' }"
                        x-show="myTeam !== null"
                        @click="switchTab('team')">
                        Team <span x-text="myTeam"></span>
                    </button>
                </div>

                <div class="chat-messages" id="chat-messages"></div>

                <form class="chat-form" @submit.prevent="sendChat">
                    <input
                        x-model="chatDraft"
                        type="text"
                        maxlength="500"
                        placeholder="Type a message&hellip;"
                        :disabled="tab === 'team' && myTeam === null"
                    >
                    <button type="submit" :disabled="!chatDraft.trim()">Send</button>
                </form>
            </div><!-- /.chat-panel -->

        </div><!-- /.main-area -->
    </div>

    <div x-show="error" x-text="error" class="error-bar"></div>
</div>

<script>
const PLAYER_ID   = <?= json_encode($player_id) ?>;
const PLAYER_NAME = <?= json_encode($player_name) ?>;
const ROOM_ID     = <?= json_encode($room_id) ?>;
const MODULES     = <?= json_encode($modules_by_id) ?>;
const PILOTS      = <?= json_encode($pilots_by_id) ?>;
const CLIENT_VERSION = <?= json_encode($client_version) ?>;

function roomApp() {
    return {
        playerId:    PLAYER_ID,
        playerName:  PLAYER_NAME,
        roomId:      ROOM_ID,
        roomState:   null,
        globalMsgs:  [],
        teamMsgs:    [],
        seenGlobal:  0,
        seenTeam:    0,
        tab:         'global',
        chatDraft:   '',
        draftState:  null,
        configState: null,
        fightState:  null,
        chargePicks: [],
        staleClient: false,
        preview:     { id: null, x: 0, y: 0 },
        selectedCard: null,
        ARROW_COLORS: { blue: '#4090ff', green: '#40c870', purple: '#a050e0', black: '#a0a0b0' },
        boardW: 0,
        boardH: 0,
        error:       '',
        copyLabel:   'Copy Link',

        // ── Lifecycle ───────────────────────────────────────────

        async init() {
            const fd = new FormData();
            fd.append('room_id', this.roomId);
            const res  = await fetch('api/join_room.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (!data.ok) {
                window.location.href = '/?error=' + encodeURIComponent(data.error);
                return;
            }
            await this.poll();
            this.renderChat();
            setInterval(() => this.poll(), 2000);
        },

        // ── Polling ─────────────────────────────────────────────

        async poll() {
            const qs = new URLSearchParams({
                room_id:      this.roomId,
                since_global: this.seenGlobal,
                since_team:   this.seenTeam,
            });
            let res;
            try { res = await fetch('api/poll.php?' + qs); }
            catch { return; }
            if (!res.ok) return;
            let data;
            try { data = await res.json(); }
            catch { this.error = 'Poll error: invalid server response'; return; }
            if (!data.ok) { this.error = data.error; return; }
            if (data.client_version && data.client_version !== CLIENT_VERSION) this.staleClient = true;

            const prevPhase = this.phase;
            this.roomState   = data.room;
            this.draftState  = data.draft;
            this.configState = data.config;
            const prevLogLen = this.fightState?.log?.length ?? 0;
            this.fightState  = data.fight;
            if (!this.fightState?.pending) this.chargePicks = [];
            if (this.phase !== prevPhase) this.selectedCard = null;
            if ((data.fight?.log?.length ?? 0) !== prevLogLen) this.renderFightLog();

            let chatChanged = false;
            const newGlobal = data.chat.global.filter(m => m.seq > this.seenGlobal);
            if (newGlobal.length) {
                this.globalMsgs = this.globalMsgs.concat(newGlobal);
                this.seenGlobal = newGlobal[newGlobal.length - 1].seq;
                chatChanged = true;
            }
            const newTeam = data.chat.team.filter(m => m.seq > this.seenTeam);
            if (newTeam.length) {
                this.teamMsgs = this.teamMsgs.concat(newTeam);
                this.seenTeam = newTeam[newTeam.length - 1].seq;
                chatChanged = true;
            }
            if (chatChanged) this.renderChat();

            if (this.myTeam === null && this.tab === 'team') { this.tab = 'global'; this.renderChat(); }
        },

        // ── Actions ─────────────────────────────────────────────

        async sendChat() {
            const text = this.chatDraft.trim();
            if (!text) return;
            this.chatDraft = '';
            const fd = new FormData();
            fd.append('room_id', this.roomId);
            fd.append('channel', this.tab);
            fd.append('text',    text);
            await fetch('api/send_chat.php', { method: 'POST', body: fd });
            await this.poll();
        },

        async setTeam(team) {
            const fd = new FormData();
            fd.append('room_id', this.roomId);
            fd.append('team',    team ?? '');
            await fetch('api/set_team.php', { method: 'POST', body: fd });
            await this.poll();
        },

        async randomizeTeams() {
            const fd = new FormData();
            fd.append('room_id', this.roomId);
            await fetch('api/randomize_teams.php', { method: 'POST', body: fd });
            await this.poll();
        },

        async startGame() {
            const fd = new FormData();
            fd.append('room_id', this.roomId);
            const res  = await fetch('api/start_game.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (!data.ok) { this.error = data.error; return; }
            await this.poll();
        },

        async draftPick(pos) {
            if (this.draftState?.my_team_ready) return;
            const fd = new FormData();
            fd.append('room_id',  this.roomId);
            fd.append('position', pos);
            await fetch('api/draft_pick.php', { method: 'POST', body: fd });
            await this.poll();
        },

        copyLink() {
            navigator.clipboard.writeText(window.location.href).then(() => {
                this.copyLabel = 'Copied!';
                setTimeout(() => this.copyLabel = 'Copy Link', 1800);
            });
        },

        renderChat() {
            const msgs = this.tab === 'global' ? this.globalMsgs : this.teamMsgs;
            const el = document.getElementById('chat-messages');
            if (!el) return;
            el.innerHTML = '';
            if (msgs.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'chat-empty';
                empty.textContent = 'No messages yet.';
                el.appendChild(empty);
                return;
            }
            for (const msg of msgs) {
                const row = document.createElement('div');
                row.className = 'msg-row' + (msg.pid === this.playerId ? ' mine' : '');
                const meta = document.createElement('div');
                meta.className = 'msg-meta';
                const nameSpan = document.createElement('span');
                nameSpan.textContent = msg.name;
                const timeSpan = document.createElement('span');
                timeSpan.textContent = this.fmtTime(msg.ts);
                meta.appendChild(nameSpan);
                meta.appendChild(timeSpan);
                const bubble = document.createElement('div');
                bubble.className = 'msg-bubble';
                bubble.textContent = msg.text;
                row.appendChild(meta);
                row.appendChild(bubble);
                el.appendChild(row);
            }
            el.scrollTop = el.scrollHeight;
        },

        switchTab(t) {
            this.tab = t;
            this.renderChat();
        },

        scrollDown() {
            const el = document.getElementById('chat-messages');
            if (el) el.scrollTop = el.scrollHeight;
        },

        fmtTime(ts) {
            const d = new Date(ts * 1000);
            return d.getHours().toString().padStart(2, '0') + ':' +
                   d.getMinutes().toString().padStart(2, '0');
        },

        // ── Draft helpers ────────────────────────────────────────

        cardInfo(id) { return MODULES[id] ?? MODULES[id?.split(':')[0]] ?? null; },

        hardpointIcons(card) {
            if (!card) return '';
            const isPart = card.type === 'part';
            const count  = isPart ? card.hardpoints_offered : card.hardpoints_required;
            if (!count) return '';
            const cls = isPart ? 'hp-offered' : 'hp-required';
            return Array.from({length: count}, () => `<span class="hp-hex ${cls}"></span>`).join('');
        },

        energySquaresHtml(card) {
            if (!card || !card.energy_max) return '';
            return Array.from({length: card.energy_max}, (_, i) =>
                `<span class="energy-sq${i < card.energy_min ? ' filled' : ''}"></span>`
            ).join('');
        },

        // Energy slots of a module in a fight: solid border = activation cost, dashed = storage; filled = holds a cube
        fightEnergyHtml(mech, id) {
            const card = this.cardInfo(id);
            if (!card || !card.energy_max) return '';
            const cubes = mech.energy?.[id] ?? card.energy_min ?? 0;
            return Array.from({length: card.energy_max}, (_, i) =>
                `<span class="energy-sq fight-sq${i < card.energy_min ? ' cost' : ''}${i < cubes ? ' cube' : ''}"></span>`
            ).join('');
        },

        rangeHtml(card) {
            if (!card || !card.range) return '';
            const cells = [1,2,3,4,5].map(n =>
                card.range.includes(String(n))
                    ? `<span class="range-n">${n}</span>`
                    : `<span class="range-dot">·</span>`
            );
            return `[${cells.join('')}]`;
        },

        isMyPick(pos) {
            return (this.draftState?.player_picks?.[this.playerId] ?? []).includes(pos);
        },

        isTeammatePick(pos) {
            const picks = this.draftState?.player_picks ?? {};
            const teammateIds = (this.roomState?.players ?? [])
                .filter(p => p.team === this.myTeam && !p.is_me)
                .map(p => p.id);
            return teammateIds.some(pid => (picks[pid] ?? []).includes(pos));
        },

        cardClass(pos) {
            const mine      = this.isMyPick(pos);
            const teammate  = this.isTeammatePick(pos);
            const locked    = this.draftState?.my_team_ready ?? false;
            return {
                'card-mine':     mine,
                'card-teammate': teammate,
                'card-locked':   locked || teammate,
            };
        },

        // Board tokens. Each mech keeps its own height on the board (and a small
        // sideways nudge), so mechs sharing a tile never overlap. Keyed per mech
        // so the same element moves and its position change animates.
        get boardTokens() {
            const LANES = { '1-0': [0.45, -5], '2-0': [0.27, 6], '1-1': [0.81, 4], '2-1': [0.63, -6] };   // [height fraction, x px]
            const mechs = [];
            for (const tk of ['1', '2']) {
                (this.fightState?.mechs?.[tk] ?? []).forEach((m, mi) => {
                    const [y, dx] = LANES[tk + '-' + mi] ?? [0.55, 0];
                    // A mech destroyed by the current card stays on the board (wrecked) until the next flip
                    const wrecked = m.hp <= 0 && m.destroyed_on === this.fightState?.flips;
                    mechs.push({ key: tk + '-' + mi, pilot: m.pilot, name: m.pilot_name, pos: m.pos,
                                 destroyed: m.hp <= 0 && !wrecked, wrecked, y, dx });
                });
            }
            return mechs;
        },

        // Arrows from attacker to target for each hit of the current card, plus
        // a dashed one for an attack waiting on the defender's shield choice.
        get boardArrows() {
            const W = this.boardW, H = this.boardH;
            if (!W || !H || !this.fightState) return [];
            const at = {};
            for (const t of this.boardTokens) {
                at[t.pilot] = { x: (t.pos + 0.5) * W / 6 + t.dx, y: t.y * H };
            }
            const hits = (this.fightState.attacks ?? []).map(a => ({ ...a }));
            const p = this.fightState.pending;
            if (p?.kind === 'defend') {
                const tgt = this.fightState.mechs?.[p.team]?.[p.mech];
                if (tgt) hits.push({ from: this.fightState.current?.pilot, to: tgt.pilot, pending: true,
                                     damage: p.hit?.dmg, weapon: p.hit?.weapon });
            }
            const R = 14;   // stop short of the token's edge
            return hits.map((h, i) => {
                const a = at[h.from], b = at[h.to];
                if (!a || !b) return null;
                // Arc upwards; repeated hits on the same target arc a little higher
                const lift = 22 + 10 * hits.slice(0, i).filter(o => o.from === h.from && o.to === h.to).length;
                const dist = Math.hypot(b.x - a.x, b.y - a.y);
                const cx = (a.x + b.x) / 2, cy = Math.min(a.y, b.y) - lift - (dist < 2 * R ? 20 : 0);
                const start = this.towards(a, { x: cx, y: cy }, R), end = this.towards(b, { x: cx, y: cy }, R);
                return {
                    key: (this.fightState.discard_count ?? 0) + '-' + i + (h.pending ? 'p' : ''),
                    d: `M${start.x},${start.y} Q${cx},${cy} ${end.x},${end.y}`,
                    lx: cx, ly: (start.y + 2 * cy + end.y) / 4 - 4,
                    color: this.ARROW_COLORS[h.from] ?? '#f0a030',
                    pending: !!h.pending,
                    damage: h.damage,
                    label: h.pending ? h.damage + '?' : (h.damage > 0 ? '−' + h.damage : 'blocked'),
                };
            }).filter(Boolean);
        },

        towards(p, q, r) {
            const d = Math.hypot(q.x - p.x, q.y - p.y) || 1;
            return { x: p.x + (q.x - p.x) * r / d, y: p.y + (q.y - p.y) * r / d };
        },

        get canFlip() {
            const f = this.fightState;
            return (f?.deck_count ?? 0) > 0 && !f?.pending && !f?.result;
        },

        get picksNeeded() {
            const myPicks = this.draftState?.player_picks?.[this.playerId]?.length ?? 0;
            const teamPlayers = (this.roomState?.players ?? []).filter(p => p.team === this.myTeam);
            const isSolo = teamPlayers.length === 1;
            return isSolo ? Math.max(0, 2 - myPicks) : Math.max(0, 1 - myPicks);
        },

        pilotName(color) { return PILOTS[color] ?? color; },

        // ── Derived state ────────────────────────────────────────

        get phase()    { return this.roomState?.phase ?? 'lobby'; },
        get players()  { return this.roomState?.players ?? []; },
        get myTeam()   { return this.players.find(p => p.is_me)?.team ?? null; },
        get unassigned() { return this.players.filter(p => p.team === null); },

        playersOnTeam(t) { return this.players.filter(p => p.team === t); },

        get canStart() {
            const ps = this.players;
            return ps.length >= 2
                && ps.filter(p => p.team === 1).length >= 1
                && ps.filter(p => p.team === 2).length >= 1
                && ps.every(p => p.team !== null);
        },

        // ── Mech helpers ─────────────────────────────────────────

        capFirst(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; },

        mechPilotColor(mechIdx, team = this.myTeam) {
            const teamPilots = this.roomState?.pilots?.[String(team)] ?? [];
            return teamPilots[mechIdx] ?? 'blue';
        },

        mechLabel(mechIdx, team = this.myTeam) {
            return this.pilotName(this.mechPilotColor(mechIdx, team));
        },

        // Draft mech previews: own team first; the opponent's only once they have a layout
        draftMechSides() {
            const mine = String(this.myTeam);
            const opp  = mine === '1' ? '2' : '1';
            const tm   = this.draftState?.team_mechs ?? {};
            const sides = [{ team: mine, mine: true, mechs: tm[mine] ?? [] }];
            if ((tm[opp] ?? []).length) sides.push({ team: opp, mine: false, mechs: tm[opp] });
            return sides;
        },

        slotLabel(slot) {
            return { head: 'Head', torso: 'Torso', r_arm: 'R Arm', l_arm: 'L Arm', legs: 'Legs' }[slot] ?? slot;
        },

        slotDefault(slot) {
            return slot === 'head' ? '— (empty)' : 'Default (1 hardp)';
        },

        slotClass(mech, slot) {
            const filled    = mech.slots[slot] != null;
            const card      = this.selectedCard ? this.cardInfo(this.selectedCard) : null;
            const canAccept = card && card.type === 'part' && this.slotAcceptsCard(slot, card);
            return {
                'slot-filled':    filled,
                'slot-empty':     !filled,
                'slot-highlight': !filled && !!canAccept,
                'slot-no-default':slot === 'head' && !filled,
            };
        },

        slotAcceptsCard(slot, card) {
            const parts = card.parts ?? [];
            if (!parts.length || parts.includes('any')) return true;
            if (slot === 'r_arm' || slot === 'l_arm') return parts.includes('arm');
            return parts.includes(slot);
        },

        // ── Config actions ───────────────────────────────────────

        configSelectCard(cardId) {
            this.selectedCard = this.selectedCard === cardId ? null : cardId;
        },

        // Click on the part row: assign selected part, or unassign existing part
        configPartClick(mechIdx, slot, existingPartId) {
            if (this.selectedCard && this.cardInfo(this.selectedCard)?.type === 'part') {
                this.configAssign(mechIdx, slot, this.selectedCard);
                this.selectedCard = null;
            } else if (existingPartId && !this.selectedCard) {
                this.configAssign(mechIdx, slot, existingPartId);
            }
        },

        // Click on the "+ Add" drop zone: attach selected equipment to this slot
        configEquipAdd(mechIdx, slot) {
            if (this.selectedCard && this.cardInfo(this.selectedCard)?.type === 'equipment') {
                this.configAssign(mechIdx, slot, this.selectedCard);
                this.selectedCard = null;
            }
        },

        slotHardpFree(mech, slot) {
            const hp = mech.slot_hp?.[slot];
            return hp ? hp.available - hp.used : 0;
        },

        slotAcceptsEquip(slot, card, mech) {
            if (!card || !this.slotAcceptsCard(slot, card)) return false;
            const free = this.slotHardpFree(mech, slot);
            return free >= (card.hardpoints_required ?? 0);
        },

        async nextRound() {
            const fd = new FormData();
            fd.append('room_id', this.roomId);
            const res = await fetch('api/next_round.php', { method: 'POST', body: fd }).then(r => r.json());
            if (!res.ok) alert(res.error);
            await this.poll();
        },

        async fightFlip() {
            const fd = new FormData();
            fd.append('room_id', this.roomId);
            await fetch('api/fight_flip.php', { method: 'POST', body: fd });
            await this.poll();
        },

        // Show the full card next to the hovered module, kept inside the viewport
        showCardPreview(id, ev) {
            if (!id || !this.cardInfo(id)) return;
            const r = ev.currentTarget.getBoundingClientRect();
            const w = 190, h = 190, gap = 8;
            let x = r.right + gap;
            if (x + w > window.innerWidth - gap) x = Math.max(gap, r.left - w - gap);
            const y = Math.min(Math.max(gap, r.top), window.innerHeight - h - gap);
            this.preview = { id, x, y };
        },

        hideCardPreview() { this.preview = { id: null, x: 0, y: 0 }; },

        fightResultDetail() {
            const r = this.fightState?.result;
            if (!r) return '';
            const how = r.reason === 'wipe' ? 'A team was wiped out.' : 'The Fight Deck ran out.';
            const s1 = r.stats['1'], s2 = r.stats['2'];
            const by = {
                eliminated: `Mechs eliminated: ${s1.eliminated}–${s2.eliminated}.`,
                damage:     `Mechs eliminated tied ${s1.eliminated}–${s2.eliminated}; damage dealt: ${s1.damage}–${s2.damage}.`,
                progress:   `Eliminations and damage tied; tiles advanced into enemy territory: ${Math.max(s1.progress, 0)}–${Math.max(s2.progress, 0)}.`,
                draw:       'Eliminations, damage and territory all tied.',
            }[r.decided_by];
            return `${how} ${by}`;
        },

        enemyPos(pilot) {
            for (const tk of ['1', '2'])
                for (const m of (this.fightState?.mechs?.[tk] ?? []))
                    if (m.pilot === pilot) return m.pos;
            return 0;
        },

        defendDamagePreview() {
            const p = this.fightState?.pending;
            if (p?.kind !== 'defend') return '';
            const def = p.free_def + this.chargePicks.reduce((sum, id) => sum + (p.details[id]?.def ?? 0), 0);
            return Math.max(0, p.hit.dmg - def) + ' of ' + p.hit.dmg;
        },

        toggleChargePick(id) {
            this.chargePicks = this.chargePicks.includes(id)
                ? this.chargePicks.filter(x => x !== id)
                : [...this.chargePicks, id];
        },

        async fightChoose(skip = false) {
            const fd = new FormData();
            fd.append('room_id', this.roomId);
            if (!skip) for (const id of this.chargePicks) fd.append('ids[]', id);
            const res = await fetch('api/fight_choose.php', { method: 'POST', body: fd }).then(r => r.json());
            if (!res.ok) alert(res.error);
            this.chargePicks = [];
            this.hideCardPreview();
            await this.poll();
        },

        renderFightLog() {
            const el = document.getElementById('fight-log');
            if (!el) return;
            const entries = this.fightState?.log ?? [];
            el.innerHTML = '';
            for (const entry of entries) {
                const line = document.createElement('div');
                line.className = 'fight-log-entry';
                line.textContent = entry;
                el.appendChild(line);
            }
            el.scrollTop = el.scrollHeight;
        },

        async configToggleReady() {
            const fd = new FormData();
            fd.append('room_id', this.roomId);
            await fetch('api/config_ready.php', { method: 'POST', body: fd });
            await this.poll();
        },

        async configAssign(mechIdx, slot, cardId) {
            const fd = new FormData();
            fd.append('room_id',  this.roomId);
            fd.append('mech_idx', mechIdx);
            fd.append('slot',     slot);
            fd.append('card_id',  cardId);
            await fetch('api/config_assign.php', { method: 'POST', body: fd });
            await this.poll();
        },

    };
}
</script>
</body>
</html>
