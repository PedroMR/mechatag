<?php
$player_name = $_COOKIE['player_name'] ?? null;
$has_name    = $player_name !== null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MechaTag</title>
<link rel="stylesheet" href="css/style.css">
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>
</head>
<body>

<div class="lobby-wrap" x-data="lobby()" x-init="init()">
    <h1>MechaTag</h1>

    <!-- Step 1: enter name -->
    <div x-show="!hasName">
        <h2>Choose a name</h2>
        <form @submit.prevent="setName">
            <input x-model="name" type="text" maxlength="32" placeholder="Your name" autofocus>
            <br><br>
            <button type="submit" :disabled="loading || !name.trim()">Continue</button>
        </form>
    </div>

    <!-- Step 2: lobby actions -->
    <div x-show="hasName">
        <p class="lobby-meta">
            Playing as <strong x-text="name"></strong> &mdash;
            <a @click.prevent="changeName">change</a> &middot;
            <a href="api/logout.php">logout</a>
        </p>

        <div class="lobby-actions">
            <button @click="createRoom" :disabled="loading">Create Room</button>

            <div class="join-row">
                <input
                    x-model="joinCode"
                    type="text"
                    maxlength="6"
                    placeholder="Room code"
                    @input="joinCode = joinCode.replace(/[^A-Fa-f0-9]/g, '').toUpperCase()"
                    @keydown.enter="joinRoom"
                >
                <button @click="joinRoom" :disabled="loading || joinCode.length !== 6">Join</button>
            </div>
        </div>
    </div>

    <p x-show="error" x-text="error" class="error-bar" style="margin-top:1rem;border-radius:4px"></p>
</div>

<script>
function lobby() {
    return {
        name:     <?= json_encode($player_name ?? '') ?>,
        hasName:  <?= json_encode($has_name) ?>,
        redirect: '',
        joinCode: '',
        loading:  false,
        error:    '',

        init() {
            const p = new URLSearchParams(window.location.search);
            if (p.get('error'))    this.error    = p.get('error');
            if (p.get('redirect')) this.redirect = p.get('redirect');
            // Already named and arriving via a room link — go straight there
            if (this.hasName && this.redirect) {
                window.location.href = 'room.php?room=' + this.redirect;
            }
        },

        async setName() {
            const trimmed = this.name.trim();
            if (!trimmed) return;
            this.loading = true;
            this.error   = '';
            const fd = new FormData();
            fd.append('name', trimmed);
            const res  = await fetch('api/set_name.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.ok) {
                this.name    = data.name;
                this.hasName = true;
                if (this.redirect) {
                    window.location.href = 'room.php?room=' + this.redirect;
                    return;
                }
            } else {
                this.error = data.error;
            }
            this.loading = false;
        },

        changeName() {
            this.hasName = false;
            this.$nextTick(() => document.querySelector('input[type=text]')?.focus());
        },

        async createRoom() {
            this.loading = true;
            this.error   = '';
            const res  = await fetch('api/create_room.php', { method: 'POST' });
            const data = await res.json();
            if (data.ok) {
                window.location.href = 'room.php?room=' + data.room_id;
            } else {
                this.error   = data.error;
                this.loading = false;
            }
        },

        async joinRoom() {
            if (this.joinCode.length !== 6) return;
            this.loading = true;
            this.error   = '';
            const fd = new FormData();
            fd.append('room_id', this.joinCode);
            const res  = await fetch('api/join_room.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.ok) {
                window.location.href = 'room.php?room=' + data.room_id;
            } else {
                this.error   = data.error;
                this.loading = false;
            }
        },
    };
}
</script>
</body>
</html>
