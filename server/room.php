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
        <h1>MechaTag</h1>
        <div class="room-code">
            <span>Room:</span>
            <strong x-text="roomId"></strong>
            <button class="btn-sm btn-ghost" @click="copyLink" x-text="copyLabel"></button>
        </div>
        <div class="room-player">Playing as <strong><?= htmlspecialchars($player_name) ?></strong> &mdash; <a href="api/logout.php">logout</a></div>
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
                            <span x-show="p.is_me"   class="badge badge-you">you</span>
                            <span x-show="!p.online" class="badge badge-off">away</span>
                        </li>
                    </template>
                    <li x-show="playersOnTeam(1).length === 0" class="empty-slot">empty</li>
                </ul>
                <button class="btn-sm btn-ghost"
                    x-show="myTeam !== 1"
                    @click="setTeam(1)">Join Team 1</button>
                <button class="btn-sm btn-danger"
                    x-show="myTeam === 1"
                    @click="setTeam(null)">Leave</button>
            </div>

            <!-- Team 2 -->
            <div class="team-block team-2">
                <h3>Team 2</h3>
                <ul class="player-list">
                    <template x-for="p in playersOnTeam(2)" :key="p.id">
                        <li :class="{ offline: !p.online }">
                            <span x-text="p.name"></span>
                            <span x-show="p.is_me"   class="badge badge-you">you</span>
                            <span x-show="!p.online" class="badge badge-off">away</span>
                        </li>
                    </template>
                    <li x-show="playersOnTeam(2).length === 0" class="empty-slot">empty</li>
                </ul>
                <button class="btn-sm btn-ghost"
                    x-show="myTeam !== 2"
                    @click="setTeam(2)">Join Team 2</button>
                <button class="btn-sm btn-danger"
                    x-show="myTeam === 2"
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

            <!-- Actions -->
            <div class="sidebar-actions">
                <button class="btn-ghost" @click="randomizeTeams">Randomize Teams</button>
                <button class="btn-start" x-show="canStart" @click="startGame">Start Game</button>
            </div>
        </aside>

        <!-- ── Chat panel ─────────────────────────────────────── -->
        <main class="chat-panel">
            <div class="chat-tabs">
                <button :class="{ active: tab === 'global' }" @click="tab = 'global'">Global</button>
                <button :class="{ active: tab === 'team' }"
                    x-show="myTeam !== null"
                    @click="tab = 'team'">
                    Team <span x-text="myTeam"></span>
                </button>
            </div>

            <div class="chat-messages" id="chat-messages">
                <template x-for="msg in visibleMessages" :key="tab + msg.seq">
                    <div class="msg-row" :class="{ mine: msg.pid === playerId }">
                        <div class="msg-meta">
                            <span x-text="msg.name"></span>
                            <span x-text="fmtTime(msg.ts)"></span>
                        </div>
                        <div class="msg-bubble" x-text="msg.text"></div>
                    </div>
                </template>
                <div x-show="visibleMessages.length === 0" class="chat-empty">No messages yet.</div>
            </div>

            <form class="chat-form" @submit.prevent="sendChat">
                <input
                    x-model="draft"
                    type="text"
                    maxlength="500"
                    placeholder="Type a message&hellip;"
                    :disabled="tab === 'team' && myTeam === null"
                >
                <button type="submit" :disabled="!draft.trim()">Send</button>
            </form>
        </main>
    </div>

    <div x-show="error" x-text="error" class="error-bar"></div>
</div>

<script>
const PLAYER_ID   = <?= json_encode($player_id) ?>;
const PLAYER_NAME = <?= json_encode($player_name) ?>;
const ROOM_ID     = <?= json_encode($room_id) ?>;

function roomApp() {
    return {
        playerId:    PLAYER_ID,
        playerName:  PLAYER_NAME,
        roomId:      ROOM_ID,
        roomState:   null,   // public room state from server
        globalMsgs:  [],
        teamMsgs:    [],
        seenGlobal:  0,
        seenTeam:    0,
        tab:         'global',
        draft:       '',
        error:       '',
        copyLabel:   'Copy Link',

        // ── Lifecycle ───────────────────────────────────────────

        async init() {
            // Register/rejoin
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
            catch { return; } // offline, retry next tick
            if (!res.ok) return;
            const data = await res.json();
            if (!data.ok) { this.error = data.error; return; }

            this.roomState = data.room;

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

            // If we got kicked off a team, fall back to global tab
            if (this.myTeam === null && this.tab === 'team') this.tab = 'global';
        },

        // ── Actions ─────────────────────────────────────────────

        async sendChat() {
            const text = this.draft.trim();
            if (!text) return;
            this.draft = '';
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
            // Placeholder — game phase transitions come later
            alert('Game start not yet implemented.');
        },

        copyLink() {
            navigator.clipboard.writeText(window.location.href).then(() => {
                this.copyLabel = 'Copied!';
                setTimeout(() => this.copyLabel = 'Copy Link', 1800);
            });
        },

        scrollDown() {
            this.$nextTick(() => {
                const el = document.getElementById('chat-messages');
                if (el) el.scrollTop = el.scrollHeight;
            });
        },

        fmtTime(ts) {
            const d = new Date(ts * 1000);
            return d.getHours().toString().padStart(2, '0') + ':' +
                   d.getMinutes().toString().padStart(2, '0');
        },

        // ── Derived state ────────────────────────────────────────

        get players()    { return this.roomState?.players ?? []; },
        get myTeam()     { return this.players.find(p => p.is_me)?.team ?? null; },
        get unassigned() { return this.players.filter(p => p.team === null); },

        playersOnTeam(t) { return this.players.filter(p => p.team === t); },

        get visibleMessages() { return this.tab === 'global' ? this.globalMsgs : this.teamMsgs; },

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
