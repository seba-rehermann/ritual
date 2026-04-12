/**
 * Reproductor y visualizador de la portada.
 */
(function () {
    var listaEl = document.getElementById('sebaji-lista-json');
    var tokenEl = document.getElementById('sebaji-token-json');
    if (!listaEl || !tokenEl) return;

    var lista;
    var PLAY_TOKEN;
    try {
        lista = JSON.parse(listaEl.textContent);
        PLAY_TOKEN = JSON.parse(tokenEl.textContent);
    } catch (e) {
        return;
    }

    var player = document.getElementById('mplayer');
    var info = document.getElementById('now-playing');
    var canvas = document.getElementById('visualizer');
    if (!player || !info || !canvas) return;

    var ctx = canvas.getContext('2d');
    var audioCtx, analyser, source;
    var currentIdx = 0;
    var rafId = null;

    function initAudio() {
        if (audioCtx) return;
        audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        analyser = audioCtx.createAnalyser();
        source = audioCtx.createMediaElementSource(player);
        source.connect(analyser);
        analyser.connect(audioCtx.destination);
        analyser.fftSize = 64;
    }

    function draw() {
        rafId = requestAnimationFrame(draw);
        var bufferLength = analyser.frequencyBinCount;
        var dataArray = new Uint8Array(bufferLength);
        analyser.getByteFrequencyData(dataArray);
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        var barWidth = (canvas.width / bufferLength) * 2.5;
        var x = 0;
        for (var i = 0; i < bufferLength; i++) {
            var barHeight = dataArray[i] / 3;
            ctx.fillStyle = 'rgba(212, 122, 36, ' + (barHeight / 60 + 0.2) + ')';
            ctx.fillRect(x, canvas.height - barHeight, barWidth - 2, barHeight);
            x += barWidth;
        }
    }

    function startVisualizerLoop() {
        if (!analyser || rafId !== null) return;
        draw();
    }

    function stopVisualizerLoop() {
        if (rafId !== null) {
            cancelAnimationFrame(rafId);
            rafId = null;
            ctx.clearRect(0, 0, canvas.width, canvas.height);
        }
    }

    player.addEventListener('play', startVisualizerLoop);
    player.addEventListener('pause', stopVisualizerLoop);
    player.addEventListener('ended', stopVisualizerLoop);

    function notifyPlay(name) {
        var fd = new URLSearchParams();
        fd.set('play_notif', '1');
        fd.set('track', name);
        fd.set('token', PLAY_TOKEN);
        fetch('index.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: fd.toString(),
            credentials: 'same-origin'
        });
    }

    window.playTrack = function (idx, name) {
        initAudio();
        if (audioCtx.state === 'suspended') audioCtx.resume();
        currentIdx = idx;
        player.src = lista[idx];
        player.play();
        var cleanName = name.replace(/_/g, ' ').replace(/\.[^/.]+$/, '');
        info.textContent = '\u25C8 ' + cleanName.toUpperCase() + ' \u25C8';
        notifyPlay(name);
        document.querySelectorAll('.track-list li').forEach(function (li) {
            li.classList.remove('active-track');
        });
        var el = document.getElementById('t-' + idx);
        if (el) el.classList.add('active-track');
    };

    window.playAll = function () {
        if (lista.length > 0) window.playTrack(0, lista[0].split('/').pop());
    };
    window.playOracle = function () {
        if (lista.length > 0) {
            var r = Math.floor(Math.random() * lista.length);
            window.playTrack(r, lista[r].split('/').pop());
        }
    };

    player.onended = function () {
        var n = currentIdx + 1;
        if (n < lista.length) window.playTrack(n, lista[n].split('/').pop());
    };

    var cb = document.getElementById('chat-box');
    if (cb) cb.scrollTop = cb.scrollHeight;

    var trackList = document.querySelector('.track-list');
    if (trackList) {
        trackList.addEventListener('click', function (e) {
            var li = e.target.closest('li');
            if (!li || !trackList.contains(li)) return;
            var m = /^t-(\d+)$/.exec(li.id || '');
            if (!m) return;
            var idx = parseInt(m[1], 10);
            var name = li.getAttribute('data-name');
            if (name !== null && !isNaN(idx)) window.playTrack(idx, name);
        });
    }

    var b1 = document.getElementById('btn-sintonia-continua');
    if (b1) b1.addEventListener('click', function () { window.playAll(); });
    var b2 = document.getElementById('btn-azar-sagrado');
    if (b2) b2.addEventListener('click', function () { window.playOracle(); });
})();
