<?php
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
<link rel="stylesheet" href="css/style.css">
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>
</head>
<body>

<div class="room-shell" x-data="roomApp()" x-init="init()">

    <!-- Header -->
    <header class="room-header">
        <h1><a href="/">MechaTag</a></h1>
        <div class="room-code">
            <span>Room:</span>
            <strong x-text="roomId"></strong>
            <button class="btn-sm btn-ghost" @click="copyLink" x-text="copyLabel"></button>
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
                        — pick <strong x-text="picksNeeded"></strong>
                    </span>
                </div>
                <div class="card-grid">
                    <template x-for="(cardId, idx) in (draftState?.hand ?? [])" :key="idx">
                        <div class="module-card"
                             :class="cardClass(idx)"
                             @click="draftPick(idx)">
                            <div class="card-top">
                                <div class="card-name" x-text="cardInfo(cardId)?.name ?? cardId"></div>
                                <div class="card-hp" x-html="hardpointIcons(cardInfo(cardId))"></div>
                            </div>
                            <div class="card-meta">
                                <span class="card-type-badge" :class="'type-' + (cardInfo(cardId)?.type ?? '')"
                                      x-text="cardInfo(cardId)?.type"></span>
                                <span class="card-tier" x-text="'T' + (cardInfo(cardId)?.tier ?? '')"></span>
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
                        <div class="module-card collected">
                            <div class="card-top">
                                <div class="card-name" x-text="cardInfo(cardId)?.name ?? cardId"></div>
                                <div class="card-hp" x-html="hardpointIcons(cardInfo(cardId))"></div>
                            </div>
                            <div class="card-meta">
                                <span class="card-type-badge" :class="'type-' + (cardInfo(cardId)?.type ?? '')"
                                      x-text="cardInfo(cardId)?.type"></span>
                                <span class="card-tier" x-text="'T' + (cardInfo(cardId)?.tier ?? '')"></span>
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

                <!-- Mech preview (draft phase) -->
                <div class="draft-section-label" style="margin-top:0.5rem">Mech Preview</div>
                <div class="mech-previews">
                    <template x-for="mechIdx in [0, 1]" :key="mechIdx">
                        <div class="mech-outline">
                            <img :src="'images/ulysses' + capFirst(mechPilotColor(mechIdx)) + '.png'"
                                 class="mech-img" alt="Mech">
                            <div class="mech-label" x-text="mechLabel(mechIdx)"></div>
                        </div>
                    </template>
                </div>

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
                                <span class="card-tier" x-text="'T' + (cardInfo(cardId)?.tier ?? '')"></span>
                            </div>
                            <div class="card-effects" x-text="cardInfo(cardId)?.effects"></div>
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
                                             @click="configPartClick(mechIdx, slot, mech.slots[slot])">
                                            <div class="slot-label" x-text="slotLabel(slot)"></div>
                                            <div class="slot-content"
                                                 x-text="mech.slots[slot] ? (cardInfo(mech.slots[slot])?.name ?? mech.slots[slot]) : slotDefault(slot)">
                                            </div>
                                            <span class="card-tier" x-show="cardInfo(mech.slots[slot])?.tier"
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
                                                     @click.stop="configAssign(mechIdx, slot, eqId)">
                                                    <span x-text="cardInfo(eqId)?.name ?? eqId"></span>
                                                    <span class="card-tier" x-text="'T' + (cardInfo(eqId)?.tier ?? '')"></span>
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
                                :disabled="(fightState?.deck_count ?? 0) === 0 || !!fightState?.pending || !!fightState?.result"
                                @click="fightFlip">
                            Flip Card
                        </button>
                        <div class="fight-deck-stack" x-show="(fightState?.discard_count ?? 0) > 0"
                             style="opacity:0.5">
                            <div class="fight-deck-label">Discard</div>
                            <div class="fight-deck-count" x-text="fightState?.discard_count ?? 0"></div>
                        </div>
                    </div>

                    <div class="fight-current" x-show="fightState?.current">
                        <div class="fight-card"
                             :class="'pilot-bg-' + (fightState?.current?.pilot ?? '')">
                            <div class="fight-card-pilot"
                                 x-text="pilotName(fightState?.current?.pilot)"></div>
                            <div class="fight-card-name"
                                 x-text="fightState?.current?.name"></div>
                            <div class="fight-card-effect"
                                 x-text="fightState?.current?.effect"></div>
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
                    <template x-if="fightState?.pending && myTeam === Number(fightState.pending.team)">
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
                         x-text="'Waiting for Team ' + (fightState?.pending?.team ?? '') + ' to choose modules to charge…'"></div>
                </div>

                <!-- Arena board -->
                <div class="arena-board">
                    <template x-for="tile in 6" :key="tile">
                        <div class="arena-tile" :class="{ 'arena-divider': tile === 4 }">
                            <div class="arena-tile-num" x-text="tile"></div>
                            <div class="arena-tile-tokens">
                                <template x-for="teamKey in ['1','2']" :key="teamKey">
                                    <template x-for="(mech, mi) in (fightState?.mechs?.[teamKey] ?? [])" :key="mi">
                                        <span class="arena-token"
                                              :class="['pilot-' + mech.pilot, { destroyed: mech.hp <= 0 }]"
                                              x-show="mech.hp > 0 && mech.pos === (tile - 1)"
                                              :title="mech.pilot_name"></span>
                                    </template>
                                </template>
                            </div>
                        </div>
                    </template>
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
                                              x-show="mech.defense > 0"
                                              x-text="'🛡' + mech.defense"></span>
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

            <!-- Full-card preview on hover (fight mode) -->
            <div class="module-card card-preview" x-show="preview.id" x-cloak
                 :style="'left:' + preview.x + 'px; top:' + preview.y + 'px'">
                <div class="card-top">
                    <div class="card-name" x-text="cardInfo(preview.id)?.name ?? preview.id"></div>
                    <div class="card-hp" x-html="hardpointIcons(cardInfo(preview.id))"></div>
                </div>
                <div class="card-meta">
                    <span class="card-type-badge" :class="'type-' + (cardInfo(preview.id)?.type ?? '')"
                          x-text="cardInfo(preview.id)?.type"></span>
                    <span class="card-tier" x-text="'T' + (cardInfo(preview.id)?.tier ?? '')"></span>
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
        preview:     { id: null, x: 0, y: 0 },
        selectedCard: null,
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

        mechPilotColor(mechIdx) {
            const teamPilots = this.roomState?.pilots?.[String(this.myTeam)] ?? [];
            return teamPilots[mechIdx] ?? 'blue';
        },

        mechLabel(mechIdx) {
            return this.pilotName(this.mechPilotColor(mechIdx));
        },

        slotLabel(slot) {
            return { head: 'Head', torso: 'Torso', r_arm: 'R Arm', l_arm: 'L Arm', legs: 'Legs' }[slot] ?? slot;
        },

        slotDefault(slot) {
            return slot === 'head' ? '— (empty)' : 'Default (1 HP)';
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
