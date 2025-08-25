jQuery(document).ready(function($) {
    'use strict';

    // Ensure our config is available
    if (typeof neMLPNotify === 'undefined') {
        console.error('neMLPNotify is not defined');
        return;
    }

    // Configuration
    const config = {
        checkInterval: 10000, // 10 seconds
        soundFile: neMLPNotify.audioUrl || '',
        nonce: neMLPNotify.nonce || '',
        action: 'ne_mlp_check_new_requests',
        isOrderPage: neMLPNotify.isOrderPage === 1,
        ajaxUrl: neMLPNotify.ajaxurl || ajaxurl || '',
        pluginUrl: neMLPNotify.pluginUrl || ''
    };
    
    if (!config.ajaxUrl) {
        console.error('AJAX URL is not defined');
        return;
    }

    // State
    // Start at 0; first poll will establish a server-synced baseline
    let lastCheckTime = 0;
    let audio = null;
    let isChecking = false;
    let notificationCount = 0;
    let audioUnlocked = false;
    let lastPlayedAt = 0;
    let hasBaseline = false;
    let lastTotalPending = null;
    let lastNotifyShownAt = 0;
    let audioCtx = null;
    let useBeepFallback = false;

    /**
     * Initialize the notification system
     */
    function init() {
        // Create a DOM audio element (more reliable across browsers)
        const el = document.createElement('audio');
        el.id = 'ne-mlp-audio';
        el.preload = 'auto';
        el.muted = false;
        el.volume = 1.0;
        el.loop = false;
        const src = document.createElement('source');
        src.src = config.soundFile;
        // Pick MIME type based on extension for better decoding
        const lower = (config.soundFile || '').toLowerCase();
        let mime = 'audio/mpeg';
        if (lower.endsWith('.wav')) mime = 'audio/wav';
        if (lower.endsWith('.ogg')) mime = 'audio/ogg';
        src.type = mime;
        el.appendChild(src);
        el.style.display = 'none';
        document.body.appendChild(el);
        audio = el;
        if (typeof audio.load === 'function') { audio.load(); }
        audio.addEventListener('canplaythrough', () => {
            console.log('NE-MLP notify: audio canplaythrough', config.soundFile);
            // If we previously set fallback due to a transient error, clear it
            useBeepFallback = false;
        });
        audio.addEventListener('playing', () => {
            console.log('NE-MLP notify: audio playing. muted=', audio.muted, 'vol=', audio.volume, 'time=', audio.currentTime);
        });
        audio.addEventListener('ended', () => {
            console.log('NE-MLP notify: audio ended at', audio.currentTime);
        });
        audio.addEventListener('error', (e) => {
            console.error('NE-MLP notify: audio error', e, config.soundFile);
            // Do not permanently force fallback; retry logic in playNotification will handle it
        });
        // Try to unlock audio on first user interaction (autoplay policy)
        const unlockHandler = function() {
            // Only unlock, do NOT play. Playback should occur solely on pending count increase.
            tryUnlockAudio(() => {});
        };
        ['click', 'keydown', 'touchstart'].forEach(evt => {
            window.addEventListener(evt, unlockHandler, { once: true, passive: true });
            document.addEventListener(evt, unlockHandler, { once: true, passive: true });
        });
        // Start checking for new requests
        setInterval(checkNewRequests, config.checkInterval);
        
        // Initial check after page load
        $(document).ready(function() {
            // Check immediately and then every interval
            checkNewRequests();
            
            // Listen for tab visibility changes
            document.addEventListener('visibilitychange', handleVisibilityChange);
            
            // Listen for focus events
            window.addEventListener('focus', handleWindowFocus);
        });
    }

    /**
     * Handle tab visibility changes
     */
    function handleVisibilityChange() {
        if (!document.hidden) {
            // Tab became visible, check for updates
            // Reset UI badge and force next poll to set baseline without sound
            notificationCount = 0;
            updateTabTitle();
            hasBaseline = false;
            lastTotalPending = null;
            checkNewRequests();
        }
    }

    /**
     * Handle window focus
     */
    function handleWindowFocus() {
        // Reset UI badge and force next poll to set baseline without sound
        notificationCount = 0;
        updateTabTitle();
        hasBaseline = false;
        lastTotalPending = null;
        checkNewRequests();
    }

    /**
     * Check for new pending requests
     */
    function checkNewRequests() {
        if (isChecking) return;
        
        isChecking = true;
        
        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            data: {
                action: config.action,
                last_check: lastCheckTime,
                nonce: config.nonce,
                _ajax_nonce: config.nonce,
                // Request details only on the Orders page
                is_background_check: config.isOrderPage ? 0 : 1
            },
            dataType: 'json',
            success: function(response) {
                if (response && response.success && response.data) {
                    const newCount = response.data.count || 0;
                    const timestamp = response.data.timestamp || Math.floor(Date.now() / 1000);
                    const totalPending = typeof response.data.total_pending === 'number' ? response.data.total_pending : null;
                    const prevTotal = lastTotalPending;

                    // Establish initial baseline without playing sounds
                    if (!hasBaseline) {
                        hasBaseline = true;
                        lastCheckTime = timestamp;
                        if (totalPending !== null) lastTotalPending = totalPending;
                        return;
                    }

                    // Only trigger sound when total pending increases compared to last poll
                    const shouldPlay = (totalPending !== null && prevTotal !== null)
                        ? (totalPending > prevTotal)
                        : (newCount > 0);

                    // Always advance last check time to the latest timestamp from server
                    if (timestamp && timestamp >= lastCheckTime) {
                        lastCheckTime = timestamp;
                    }

                    if (shouldPlay) {
                        
                        // Increment the badge by 1 per detection to avoid multi-increment bursts
                        notificationCount += 1;
                        
                        // Play sound and show notification
                        playNotification();
                        
                        // Update UI
                        updateTabTitle();
                        
                        // If we're on the orders page, update the list
                        if (config.isOrderPage && response.data.requests) {
                            updateOrdersList(response.data.requests);
                        }

                        // Show unified modal notification (throttled)
                        const now = Date.now();
                        if (typeof window.showMessage === 'function' && (now - lastNotifyShownAt > 3000)) {
                            const requestsUrl = (typeof neMLPNotify !== 'undefined' && neMLPNotify.requestsPageUrl) ? neMLPNotify.requestsPageUrl : null;
                            window.showMessage(
                                'New Order Request',
                                'You have new pending Order Request(s).',
                                'success',
                                {
                                    buttons: [
                                        requestsUrl ? { label: 'View Requests', className: 'button button-primary', action: function() {
                                            // Always go to the Requests page; avoid any onClose reloads
                                            try { window.top.location.assign(requestsUrl); } catch(e) {}
                                            try { window.top.location.href = requestsUrl; } catch(e) {}
                                            setTimeout(function(){ try { window.top.location.replace(requestsUrl); } catch(e) {} }, 0);
                                            return false; // prevent modal auto-close flow
                                        } } : null,
                                        { label: 'Close', className: 'button', action: 'close' }
                                    ].filter(Boolean)
                                }
                            );
                            lastNotifyShownAt = now;
                        }
                    }

                    // Advance baseline total for next poll to avoid repeated plays
                    if (typeof totalPending === 'number') {
                        lastTotalPending = totalPending;
                    }
                }
            },
            error: function(xhr, status, error) {
                console.error('Error checking for new requests:', error);
                // Retry after a delay if there's an error
                setTimeout(checkNewRequests, 30000); // Retry after 30 seconds
            },
            complete: function() {
                isChecking = false;
            }
        });
    }

    /**
     * Update the browser tab title with notification count
     */
    function updateTabTitle() {
        if (notificationCount > 0) {
            document.title = '(' + notificationCount + ') ' + document.title.replace(/^\(\d+\)\s*/, '');
        } else {
            document.title = document.title.replace(/^\(\d+\)\s*/, '');
        }
    }

    /**
     * Update the orders list with new requests
     */
    function updateOrdersList(requests) {
        // Find the orders table or container
        const $ordersTable = $('.ne-mlp-orders-table tbody');
        
        if ($ordersTable.length) {
            // Add new requests to the top of the table
            requests.forEach(function(request) {
                const row = createOrderRow(request);
                $ordersTable.prepend(row);
            });
            
            // Update the count display if it exists
            const $countDisplay = $('.order-count');
            if ($countDisplay.length) {
                const currentCount = parseInt($countDisplay.text()) || 0;
                $countDisplay.text(currentCount + requests.length);
            }
        }
    }

    /**
     * Create a table row for an order
     */
    function createOrderRow(order) {
        // Format the date
        const date = new Date(order.created_at);
        const formattedDate = date.toLocaleString();
        
        // Create the row HTML
        return `
            <tr data-order-id="${order.id}">
                <td>${order.id}</td>
                <td>${order.patient_name || 'N/A'}</td>
                <td>${order.patient_dob || 'N/A'}</td>
                <td>${order.doctor_name || 'N/A'}</td>
                <td>${formattedDate}</td>
                <td><span class="status status-pending">Pending</span></td>
                <td>
                    <a href="#" class="button view-order" data-id="${order.id}">View</a>
                </td>
            </tr>
        `;
    }

    /**
     * Play the notification sound
     */
    function playNotification() {
        if (useBeepFallback || !config.soundFile) {
            playBeep();
            lastPlayedAt = Date.now();
            return;
        }
        if (!audio) { console.warn('NE-MLP notify: audio object not ready'); playBeep(); return; }
        // Debounce: prevent rapid duplicate plays
        const now = Date.now();
        if (now - lastPlayedAt < 800) return;

        const doPlay = () => {
            try {
                // Safety: ensure it isn't muted
                audio.muted = false;
                audio.volume = Math.max(0.2, Math.min(1, audio.volume || 1));
                audio.currentTime = 0;
                const playPromise = audio.play();
                if (playPromise && typeof playPromise.then === 'function') {
                    playPromise.then(() => {
                        console.log('Audio playback started');
                        lastPlayedAt = Date.now();
                    }).catch(err => {
                        console.warn('Audio play blocked, retrying unlock...', err);
                        tryUnlockAudio(() => {
                            // Retry once after unlocking
                            audio.currentTime = 0;
                            audio.play().then(() => { 
                                console.log('Audio playback started after retry');
                                lastPlayedAt = Date.now();
                            }).catch(() => { 
                                console.log('Audio playback failed after retry');
                                useBeepFallback = true; 
                                playBeep(); 
                            });
                        });
                    });
                } else {
                    lastPlayedAt = Date.now();
                }
            } catch (error) {
                console.error('Error with audio playback:', error);
                useBeepFallback = true;
                playBeep();
            }
        };

        if (audioUnlocked) {
            doPlay();
        } else {
            tryUnlockAudio(doPlay);
        }
    }

    // Web Audio API beep fallback
    function playBeep() {
        try {
            if (!audioCtx) {
                const Ctx = window.AudioContext || window.webkitAudioContext;
                if (!Ctx) return;
                audioCtx = new Ctx();
            }
            const now = audioCtx.currentTime;
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(880, now);
            gain.gain.setValueAtTime(0.0001, now);
            osc.connect(gain);
            gain.connect(audioCtx.destination);
            osc.start(now);
            // Quick attack/decay envelope
            gain.gain.exponentialRampToValueAtTime(0.3, now + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.25);
            osc.stop(now + 0.3);
        } catch (e) {
            // Silent fallback if Web Audio is unavailable
        }
    }

    function tryUnlockAudio(callback) {
        if (!audio || audioUnlocked) { if (callback) callback(); return; }
        try {
            const wasMuted = audio.muted;
            audio.muted = true;
            const p = audio.play();
            if (p && typeof p.then === 'function') {
                p.then(() => {
                    audio.pause();
                    audio.currentTime = 0;
                    audio.muted = wasMuted;
                    audioUnlocked = true;
                    hideEnableSoundPrompt();
                    if (callback) callback();
                }).catch(() => {
                    // Leave listeners in place; user will interact again
                    if (callback) callback();
                });
            } else {
                audioUnlocked = true;
                hideEnableSoundPrompt();
                if (callback) callback();
            }
        } catch (e) {
            if (callback) callback();
        }
    }

    // Small UI helper to prompt enabling sound
    function showEnableSoundPrompt() {
        if (document.getElementById('ne-mlp-enable-sound')) return;
        const btn = document.createElement('button');
        btn.id = 'ne-mlp-enable-sound';
        btn.type = 'button';
        btn.textContent = 'Enable sound';
        btn.style.position = 'fixed';
        btn.style.right = '16px';
        btn.style.bottom = '16px';
        btn.style.zIndex = '100000';
        btn.style.padding = '8px 12px';
        btn.style.background = '#2271b1';
        btn.style.color = '#fff';
        btn.style.border = 'none';
        btn.style.borderRadius = '4px';
        btn.style.cursor = 'pointer';
        btn.style.boxShadow = '0 2px 6px rgba(0,0,0,.2)';
        btn.addEventListener('click', function() { tryUnlockAudio(() => {}); });
        document.body.appendChild(btn);
    }

    function hideEnableSoundPrompt() {
        const btn = document.getElementById('ne-mlp-enable-sound');
        if (btn) btn.remove();
    }

    // Request notification permission when the page loads
    if (typeof Notification !== 'undefined' && Notification.permission !== 'granted' && Notification.permission !== 'denied') {
        Notification.requestPermission().then(function(permission) {
            console.log('Notification permission:', permission);
        });
    }
    
    // Initialize the notification system with a small delay to ensure DOM is ready
    setTimeout(init, 1000);
    
    // Make functions available globally if needed
    window.NE_MLP_Notifications = {
        checkNewRequests: checkNewRequests,
        updateTabTitle: updateTabTitle,
        play: playNotification,
        unlock: () => tryUnlockAudio()
    };
})(jQuery);
