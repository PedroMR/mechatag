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
            <span x-show="myPilot">&mdash; <span class="pilot-tag" :class="'pilot-' + myPilotColor" x-text="myPilot"></span></span>
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
                            <span x-show="p.pilot" class="pilot-dot" :class="'pilot-' + p.pilot" :title="pilotName(p.pilot)"></span>
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
                            <span x-show="p.pilot" class="pilot-dot" :class="'pilot-' + p.pilot" :title="pilotName(p.pilot)"></span>
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
                     x-text="'Round ' + (draftState?.round ?? '?') + ' · ' + (draftState?.step === 'initial' ? 'Pick from hand' : 'Pick passed cards')">
                </div>
            </div>

            <!-- Config phase info -->
            <div class="sidebar-actions" x-show="phase === 'config'">
                <div class="phase-label">Config Phase</div>
                <div class="draft-round-label">Assign cards to your mechs</div>
            </div>
        </aside>

        <!-- ── Main area ──────────────────────────────────────── -->
        <div class="main-area" :class="{ 'draft-mode': phase === 'draft', 'config-mode': phase === 'config' }">

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
                    Available Cards (<span x-text="configAvailable().length"></span>)
                </div>
                <div class="card-grid card-grid-sm" x-show="configAvailable().length > 0">
                    <template x-for="cardId in configAvailable()" :key="cardId">
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
                            </div>
                            <div class="card-effects" x-text="cardInfo(cardId)?.effects"></div>
                            <div class="card-parts"
                                 x-text="(cardInfo(cardId)?.parts ?? []).join(' · ').toUpperCase()"></div>
                        </div>
                    </template>
                </div>
                <div class="config-empty-pool" x-show="configAvailable().length === 0">All cards assigned</div>

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
                                    <div class="mech-hp-bar">
                                        <span class="mech-hp-used" x-text="mech.hp_used"></span>
                                        <span class="mech-hp-sep">/</span>
                                        <span class="mech-hp-total" x-text="mech.hp_total"></span>
                                        <span class="mech-hp-label"> HP used</span>
                                    </div>
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
                                            <div class="slot-hp"
                                                 x-show="(mech.slot_hp?.[slot]?.available ?? 0) > 0"
                                                 :class="{ 'no-free': slotHpFree(mech, slot) === 0 }"
                                                 x-text="slotHpFree(mech, slot) + '\u2665 HP'">
                                            </div>
                                        </div>
                                        <!-- Equipment attached to this slot -->
                                        <div class="slot-equip-row"
                                             x-show="(mech.slot_hp?.[slot]?.available ?? 0) > 0">
                                            <template x-for="(eqId, ei) in (mech.equipment?.[slot] ?? [])" :key="ei">
                                                <div class="equip-tag"
                                                     @click.stop="configAssign(mechIdx, slot, eqId)">
                                                    <span x-text="cardInfo(eqId)?.name ?? eqId"></span>
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

            </div><!-- /.config-panel -->

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
            if (this.phase !== prevPhase) this.selectedCard = null;

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

        cardInfo(id) { return MODULES[id] ?? null; },

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

        get myPilot() {
            const me = this.players.find(p => p.is_me);
            return me?.pilot ? PILOTS[me.pilot] : null;
        },
        get myPilotColor() {
            return this.players.find(p => p.is_me)?.pilot ?? '';
        },

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
            const players = this.playersOnTeam(this.myTeam);
            return (players[mechIdx] ?? players[0])?.pilot ?? 'blue';
        },

        mechLabel(mechIdx) {
            const players = this.playersOnTeam(this.myTeam);
            const player  = players[mechIdx] ?? players[0];
            return player ? player.name + "'s Mech" : 'Mech ' + (mechIdx + 1);
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
            if (!parts.length) return true;  // no parts restriction: accept anywhere
            if (slot === 'r_arm' || slot === 'l_arm') return parts.includes('arm');
            return parts.includes(slot);
        },

        // ── Config actions ───────────────────────────────────────

        configAvailable() {
            if (!this.configState) return [];
            const all      = this.configState.modules;
            const assigned = new Set();
            for (const mech of this.configState.mechs) {
                for (const v of Object.values(mech.slots)) { if (v) assigned.add(v); }
                for (const slotEquip of Object.values(mech.equipment ?? {})) {
                    for (const c of slotEquip) assigned.add(c);
                }
            }
            return all.filter(id => !assigned.has(id));
        },

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

        slotHpFree(mech, slot) {
            const hp = mech.slot_hp?.[slot];
            return hp ? hp.available - hp.used : 0;
        },

        slotAcceptsEquip(slot, card, mech) {
            if (!card || !this.slotAcceptsCard(slot, card)) return false;
            const free = this.slotHpFree(mech, slot);
            return free >= (card.hardpoints_required ?? 0);
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
