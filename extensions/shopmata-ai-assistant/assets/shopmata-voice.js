(function () {
  'use strict';

  var pc = null;
  var dataChannel = null;
  var localStream = null;
  var callbacks = {};
  var peerId = null;

  function start(opts) {
    callbacks = {
      onTranscript: opts.onTranscript || function () {},
      onResponse: opts.onResponse || function () {},
      onAddToCart: opts.onAddToCart || function () {},
      onEnd: opts.onEnd || function () {},
    };

    navigator.mediaDevices.getUserMedia({ audio: true, video: false })
      .then(function (stream) {
        localStream = stream;
        return connect(opts);
      })
      .catch(function (err) {
        console.error('[smc-voice] Mic access denied:', err);
        callbacks.onEnd();
      });
  }

  function connect(opts) {
    pc = new RTCPeerConnection({
      iceServers: [{ urls: 'stun:stun.l.google.com:19302' }],
    });

    // Add local audio track
    localStream.getAudioTracks().forEach(function (track) {
      pc.addTrack(track, localStream);
    });

    // Receive remote audio (TTS)
    pc.ontrack = function (event) {
      if (event.track.kind === 'audio') {
        var audio = new Audio();
        audio.srcObject = new MediaStream([event.track]);
        audio.play().catch(function () {});
      }
    };

    // Create data channel
    dataChannel = pc.createDataChannel('messages');

    dataChannel.onmessage = function (event) {
      try {
        var msg = JSON.parse(event.data);
        switch (msg.type) {
          case 'transcript':
            callbacks.onTranscript(msg.text);
            break;
          case 'response':
            callbacks.onResponse(msg.text);
            break;
          case 'add_to_cart':
            callbacks.onAddToCart(msg);
            break;
          case 'error':
            callbacks.onResponse(msg.message || 'Something went wrong.');
            break;
        }
      } catch (e) {}
    };

    pc.oniceconnectionstatechange = function () {
      if (pc.iceConnectionState === 'disconnected' || pc.iceConnectionState === 'failed') {
        stop();
        callbacks.onEnd();
      }
    };

    // Create offer and send to gateway
    return pc.createOffer()
      .then(function (offer) { return pc.setLocalDescription(offer); })
      .then(function () {
        return new Promise(function (resolve) {
          if (pc.iceGatheringState === 'complete') return resolve();
          pc.onicegatheringstatechange = function () {
            if (pc.iceGatheringState === 'complete') resolve();
          };
        });
      })
      .then(function () {
        return fetch(opts.gatewayUrl + '/offer', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            sdp: pc.localDescription.sdp,
            shop: opts.shop,
            visitor_id: opts.visitorId,
            session_id: opts.sessionId,
          }),
        });
      })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        peerId = data.peerId;
        return pc.setRemoteDescription({ type: 'answer', sdp: data.sdp });
      })
      .catch(function (err) {
        console.error('[smc-voice] Connection error:', err);
        stop();
        callbacks.onEnd();
      });
  }

  function stop() {
    if (dataChannel) {
      try { dataChannel.close(); } catch (e) {}
      dataChannel = null;
    }

    if (pc) {
      try { pc.close(); } catch (e) {}
      pc = null;
    }

    if (localStream) {
      localStream.getTracks().forEach(function (t) { t.stop(); });
      localStream = null;
    }

    if (peerId) {
      try {
        navigator.sendBeacon(
          callbacks._gatewayUrl + '/disconnect',
          JSON.stringify({ peerId: peerId })
        );
      } catch (e) {}
      peerId = null;
    }
  }

  window.ShopmataChatVoice = { start: start, stop: stop };
})();
