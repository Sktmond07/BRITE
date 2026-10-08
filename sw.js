// sw.js - Service Worker with Sound Support + Focus Existing Tab

self.addEventListener('install', function(event) {
    console.log('Service Worker installed');
    self.skipWaiting();
});

self.addEventListener('activate', function(event) {
    console.log('Service Worker activated');
    event.waitUntil(clients.claim());
});

self.addEventListener('push', function(event) {
    console.log('Push notification received:', event);
    
    let data = {
        title: '🚨 EMERGENCY ALERT!',
        body: 'New emergency reported',
        icon: '/BRITE/logo.jpg',
        badge: '/BRITE/logo.jpg',
        sound: '/BRITE/admin/sounds/emergency_siren.mp3',
        vibrate: [500, 200, 500, 200, 500, 200, 1000],
        data: {}
    };
    
    if (event.data) {
        try {
            const parsed = event.data.json();
            data = { ...data, ...parsed };
        } catch(e) {
            data.body = event.data.text();
        }
    }
    
    const options = {
        body: data.body,
        icon: data.icon,
        badge: data.badge,
        vibrate: data.vibrate,
        requireInteraction: true,
        silent: false,
        sound: data.sound,
        data: data.data,
        actions: [
            {
                action: 'view',
                title: 'View Emergency',
                icon: '/BRITE/logo.jpg'
            },
            {
                action: 'dismiss',
                title: 'Dismiss',
                icon: '/BRITE/logo.jpg'
            }
        ]
    };
    
    event.waitUntil(
        self.registration.showNotification(data.title, options)
            .then(() => {
                return playNotificationSound(data.sound);
            })
    );
});

function playNotificationSound(soundUrl) {
    try {
        const audio = new Audio(soundUrl);
        audio.volume = 0.9;
        audio.loop = true;
        audio.play().catch(e => {
            console.log('Sound play failed:', e);
            beepFallback();
        });
        
        self.currentAudio = audio;
        
        setTimeout(() => {
            if (self.currentAudio) {
                self.currentAudio.pause();
                self.currentAudio = null;
            }
        }, 30000);
        
    } catch(e) {
        console.log('Sound error:', e);
        beepFallback();
    }
}

function beepFallback() {
    try {
        const AudioContext = self.AudioContext || self.webkitAudioContext;
        if (AudioContext) {
            const ctx = new AudioContext();
            const oscillator = ctx.createOscillator();
            const gain = ctx.createGain();
            
            oscillator.connect(gain);
            gain.connect(ctx.destination);
            
            oscillator.type = 'sine';
            gain.gain.value = 0.5;
            
            let freq = 440;
            let dir = 1;
            
            oscillator.frequency.value = freq;
            oscillator.start();
            
            const interval = setInterval(() => {
                if (dir === 1) {
                    freq += 30;
                    if (freq >= 880) dir = -1;
                } else {
                    freq -= 30;
                    if (freq <= 440) dir = 1;
                }
                oscillator.frequency.value = freq;
            }, 60);
            
            self.soundInterval = interval;
            self.soundOsc = oscillator;
            
            setTimeout(() => {
                if (self.soundInterval) clearInterval(self.soundInterval);
                if (self.soundOsc) self.soundOsc.stop();
            }, 30000);
        }
    } catch(e) {}
}

self.addEventListener('notificationclick', function(event) {
    console.log('Notification clicked:', event);
    
    // Stop any playing sound
    if (self.currentAudio) {
        self.currentAudio.pause();
        self.currentAudio = null;
    }
    if (self.soundInterval) clearInterval(self.soundInterval);
    if (self.soundOsc) self.soundOsc.stop();
    
    event.notification.close();
    
    if (event.action === 'view' || !event.action) {
        let url = '/BRITE/admin/dashboard.php';
        if (event.notification.data && event.notification.data.url) {
            url = event.notification.data.url;
        }
        
        event.waitUntil(
            clients.matchAll({ type: 'window', includeUncontrolled: true })
                .then(windowClients => {
                    for (let client of windowClients) {
                        if (client.url.includes('/BRITE/admin/') && client.url.includes('dashboard')) {
                            console.log('Found existing dashboard tab, focusing...');
                            // Just focus - NO refresh
                            return client.focus();
                        }
                    }
                    console.log('No existing dashboard tab, opening new...');
                    return clients.openWindow(url);
                })
        );
    }
});