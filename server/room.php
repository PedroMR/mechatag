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
        'name'    => $m['name'],
        'type'    => $m['type'],
        'effects' => $m['effects'] ?? '',
        'tier'    => (int)$m['tier'],
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
        </aside>

        <!-- ── Main area ──────────────────────────────────────── -->
        <div class="main-area" :class="{ 'draft-mode': phase === 'draft' }">

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
                            <div class="card-name" x-text="cardInfo(cardId)?.name ?? cardId"></div>
                            <div class="card-meta">
                                <span class="card-type-badge" :class="'type-' + (cardInfo(cardId)?.type ?? '')"
                                      x-text="cardInfo(cardId)?.type"></span>
                                <span class="card-tier" x-text="'T' + (cardInfo(cardId)?.tier ?? '')"></span>
                            </div>
                            <div class="card-effects" x-text="cardInfo(cardId)?.effects"></div>
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
                            <div class="card-name" x-text="cardInfo(cardId)?.name ?? cardId"></div>
                            <div class="card-meta">
                                <span class="card-type-badge" :class="'type-' + (cardInfo(cardId)?.type ?? '')"
                                      x-text="cardInfo(cardId)?.type"></span>
                            </div>
                            <div class="card-effects" x-text="cardInfo(cardId)?.effects"></div>
                        </div>
                    </template>
                </div>

            </div><!-- /.draft-panel -->

            <!-- Chat panel -->
            <div class="chat-panel">
                <div class="chat-tabs">
                    <button :class="{ active: tab === 'global' }" @click="tab = 'global'">Global</button>
                    <button :class="{ active: tab === 'team' }"
                        x-show="myTeam !== null"
                        @click="tab = 'team'">
                        Team <span x-text="myTeam"></span>
                    </button>
                </div>

                <div class="chat-messages" id="chat-messages">
                    <template x-for="msg in (tab === 'global' ? globalMsgs : teamMsgs)" :key="msg.seq">
                        <div class="msg-row" :class="{ mine: msg.pid === playerId }">
                            <div class="msg-meta">
                                <span x-text="msg.name"></span>
                                <span x-text="fmtTime(msg.ts)"></span>
                            </div>
                            <div class="msg-bubble" x-text="msg.text"></div>
                        </div>
                    </template>
                    <div x-show="(tab === 'global' ? globalMsgs : teamMsgs).length === 0" class="chat-empty">No messages yet.</div>
                </div>

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
            const data = await res.json();
            if (!data.ok) { this.error = data.error; return; }

            this.roomState  = data.room;
            this.draftState = data.draft;

            if (data.chat.global.length) {
                this.globalMsgs.push(...data.chat.global);
                this.seenGlobal = this.globalMsgs.at(-1).seq;
                if (this.tab === 'global') this.scrollDown();
            }
            if (data.chat.team.length) {
                this.teamMsgs.push(...data.chat.team);
                this.seenTeam = this.teamMsgs.at(-1).seq;
                if (this.tab === 'team') this.scrollDown();
            }

            if (this.myTeam === null && this.tab === 'team') this.tab = 'global';
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

        scrollDown() {
            this.$nextTick(() => {
                requestAnimationFrame(() => {
                    const el = document.getElementById('chat-messages');
                    if (el) el.scrollTop = el.scrollHeight;
                });
            });
        },

        fmtTime(ts) {
            const d = new Date(ts * 1000);
            return d.getHours().toString().padStart(2, '0') + ':' +
                   d.getMinutes().toString().padStart(2, '0');
        },

        // ── Draft helpers ────────────────────────────────────────

        cardInfo(id) { return MODULES[id] ?? null; },

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
    };
}
</script>
</body>
</html>
