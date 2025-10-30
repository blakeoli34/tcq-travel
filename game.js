// game.js - Complete Travel Edition JavaScript

// Global variables
let gameData = {};
let firebaseMessaging = null;
let dailyDeckData = { slots: [], remainingCards: 0 };
let isVetoWaiting = false;
let vetoWaitEndTime = null;
let handOverlayOpen = false;
let currentHandDeck = 'snap';
let handCards = [];
let scoreBugExpanded = false;
let refreshInterval = null;
let slideoutTimeout = null;
let waitCountdownInterval = null;
let autoCurseInterval = null;
let isInitialHandLoad = true;
let previousHandCount = 0;

// Sound management
let actionSound = new Audio('data:audio/mpeg;base64,SUQzBAAAAAABEVRYWFgAAAAtAAADY29tbWVudABCaWdTb3VuZEJhbmsuY29tIC8gTGFTb25vdGhlcXVlLm9yZwBURU5DAAAAHQAAA1N3aXRjaCBQbHVzIMKpIE5DSCBTb2Z0d2FyZQBUSVQyAAAABgAAAzIyMzUAVFNTRQAAAA8AAANMYXZmNTcuODMuMTAwAAAAAAAAAAAAAAD/80DEAAAAA0gAAAAATEFNRTMuMTAwVVVVVVVVVVVVVUxBTUUzLjEwMFVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVf/zQsRbAAADSAAAAABVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVf/zQMSkAAADSAAAAABVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVV');

// ========================================
// INITIALIZATION
// ========================================

$(document).ready(function() {
    console.log('Travel Edition game loaded');
    
    // Initialize sound on first user interaction
    let hasClicked = false;
    $(document).on('click', function(event) {
        if (!hasClicked && isSoundEnabled()) {
            hasClicked = true;
            actionSound.play().catch(() => {});
            console.log('Sounds enabled');
            $(document).off('click');
        }
    });

    if (document.querySelector('.ready-start-screen')) {
        initializeReadyToStart();
    }
    
    // Get game data from PHP
    if (typeof window.gameDataFromPHP !== 'undefined') {
        gameData = window.gameDataFromPHP;
        console.log('Game data:', gameData);
    }

    if (gameData.gameStatus === 'active') {
        updateDailyGameClock();
        setInterval(() => {
            updateDailyGameClock();
            updateCurseTimers();
        }, 1000);
    }

    setTimeout(() => {
        updateSoundIcon();
    }, 100);
    
    // Initialize Firebase
    initializeFirebase();
    checkBadgeSupport();
    
    // Setup event handlers
    setupModeButtons();
    setupModalHandlers();
    setupScoreBugHandlers();

    // CHECK DOWNTIME IMMEDIATELY
    const isDowntime = checkDowntime();
    
    setTimeout(() => {
        if (!checkDowntime()) {
            // Only load daily deck if game is active
            if (gameData && gameData.gameStatus === 'active') {
                loadDailyDeck();
            }
        }
        checkVetoWait();
        updateStatusEffects();
        if (gameData && gameData.gameStatus === 'active') {
            loadHandCards();
            updateDeckCounts();
            updateDailyDeckCount();
            checkDeckEmpty();
            checkCurseBlock();
        }
        setScorebugWidth();
        $('.score-bug, .game-timer-hand').addClass('visible');
    }, 500);

    startRefreshInterval();
    
    // Setup polling for waiting screens
    setupWaitingScreenPolling();
    
    // Clear badge when app becomes visible
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            updateAppBadge(0);
            $('.daily-slot').removeClass('loading');
            setTimeout(() => {
                $('body').removeClass('backgrounded');
            }, 1000);
        }
        if (document.hidden) {
            setScorebugWidth();
            setTimeout(() => {
                $('body').addClass('backgrounded');
            }, 500);
        }
    });

    // Touch gesture handling for hand overlay
    let touchStartY = 0;
    let touchEndY = 0;
    let isScrolling = false;

    $(document).on('touchstart', function(e) {
        const dailyDeckContainer = $(e.target).closest('.daily-deck-container')[0];

        if (dailyDeckContainer && hasOverflow(dailyDeckContainer)) {
            // Container has overflow (scrollable content), so don't handle swipe gestures
            return;
        }
        // Don't handle if touching the score bug or scrollable areas
        if ($(e.target).closest('#scoreBug, #scoreBugExpanded, #debugPanel').length > 0) {
            return;
        }

        touchStartY = e.originalEvent.changedTouches[0].screenY;
        isScrolling = false;
    });

    $(document).on('touchmove', function(e) {
        // Check if we're scrolling within debug panel
        if ($(e.target).closest('#debugPanel').length > 0) {
            isScrolling = true;
        }
    });

    $(document).on('touchend', function(e) {
        const dailyDeckContainer = $(e.target).closest('.daily-deck-container')[0];

        if (dailyDeckContainer && hasOverflow(dailyDeckContainer)) {
            // Container has overflow (scrollable content), so don't handle swipe gestures
            isScrolling = false;
            return;
        }

        // Don't handle if touching the score bug, other scrollable areas, or if we detected scrolling
        if ($(e.target).closest('#scoreBug, #scoreBugExpanded, #debugPanel').length > 0 || isScrolling) {
            isScrolling = false;
            return;
        }
        
        touchEndY = e.originalEvent.changedTouches[0].screenY;
        handleSwipe();
        isScrolling = false;
    });

    function handleSwipe() {
        const swipeThreshold = 50;
        const swipeDistance = touchStartY - touchEndY;
        
        // Swipe down (negative distance)
        if (swipeDistance < -swipeThreshold) {
            if (!handOverlayOpen) {
                toggleHandOverlay();
            }
        }
        // Swipe up (positive distance) 
        else if (swipeDistance > swipeThreshold) {
            if (handOverlayOpen) {
                toggleHandOverlay();
            }
        }
    }
});

function hasOverflow(element) {
    return element.scrollHeight > element.offsetHeight;
}

function stopRefreshInterval() {
    if (refreshInterval) {
        clearInterval(refreshInterval);
        refreshInterval = null;
    }
}

function startRefreshInterval() {
    if (!refreshInterval) {
        refreshInterval = setInterval(() => {
            if (!isVetoWaiting && !checkDowntime()) {
                loadDailyDeck();
                updateSlotActionsForModifiers();
            }
            checkVetoWait();
            checkCurseBlock();
            updateStatusEffects();
            refreshGameData();
            checkGameStatus();
            loadHandCards();
            updateCardModifiers();
            checkForDeckPeek();
            checkDeckEmpty();
            updateDailyDeckCount();
            fetch('game.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=cleanup_expired_effects'
            });
        }, 5000);
    }
}

function forceRefreshOnce() {
    stopRefreshInterval();
    setTimeout(() => {
        refreshGameData();
        setTimeout(startRefreshInterval, 1000); // Restart after 1 second
    }, 2000);
}

function checkGameStatus() {
    if (!gameData || gameData.gameStatus !== 'active') return;
    
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=check_game_status'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.status === 'completed') {
            window.location.reload();
        }
    })
    .catch(error => {
        console.error('Error checking game status:', error);
    });
}

// Ready to Start functionality
function initializeReadyToStart() {
    const button = document.getElementById('readyButton');
    if (!button) return;
    
    let isPressed = false;
    
    function startPress(e) {
        e.preventDefault();
        if (!isPressed) {
            isPressed = true;
            setReadyStatus(true);
        }
    }
    
    function endPress(e) {
        e.preventDefault();
        if (isPressed) {
            isPressed = false;
            setReadyStatus(false);
        }
    }
    
    // Mouse events
    button.addEventListener('mousedown', startPress);
    button.addEventListener('mouseup', endPress);
    button.addEventListener('mouseleave', endPress);
    
    // Touch events
    button.addEventListener('touchstart', startPress);
    button.addEventListener('touchend', endPress);
    button.addEventListener('touchcancel', endPress);
    
    // Global events to catch releases outside button
    document.addEventListener('mouseup', endPress);
    document.addEventListener('touchend', endPress);
    
    // Start status checking
    const statusInterval = setInterval(checkReadyStatus, 500);
    window.addEventListener('beforeunload', () => clearInterval(statusInterval));
}

function setReadyStatus(ready) {
    const button = document.getElementById('readyButton');
    if (ready) {
        button.classList.add('pressed');
    } else {
        button.classList.remove('pressed');
    }
    
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=set_ready_to_start&ready=${ready}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.both_ready) {
            startGame();
        }
    })
    .catch(error => {
        console.error('AJAX error:', error);
    });
}

function checkReadyStatus() {
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=check_ready_status'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const statusEl = document.getElementById('readyStatus');
            const button = document.getElementById('readyButton');
            
            if (data.both_ready) {
                statusEl.innerHTML = '<p>🚀 Starting Game...</p>';
                statusEl.className = 'ready-status both-ready';
                button.classList.add('both-ready');
                setTimeout(() => window.location.reload(), 2000);
            } else if (data.opponent_ready) {
                statusEl.innerHTML = `<p>${data.opponent_name} is ready! Hold your button to start!</p>`;
                statusEl.className = 'ready-status opponent-ready';
            } else {
                statusEl.innerHTML = '<p>Press and hold the button when you\'re ready to start!</p>';
                statusEl.className = 'ready-status';
            }
        }
    });
}

function startGame() {
    const statusEl = document.getElementById('readyStatus');
    const button = document.getElementById('readyButton');
    
    statusEl.innerHTML = '<p>🚀 Starting Game...</p>';
    statusEl.className = 'ready-status both-ready';
    button.classList.add('both-ready');
    
    setTimeout(() => {
        window.location.reload();
    }, 2000);
}

// Rules Modal
function showRulesModal() {
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=get_game_rules'
    })
    .then(response => response.json())
    .then(data => {
        const modal = document.createElement('div');
        modal.className = 'rules-modal';
        modal.innerHTML = `
            <div class="rules-modal-content">
                <div class="rules-modal-header">
                    <h2 class="rules-modal-title">Game Rules</h2>
                    <button class="rules-close" onclick="closeRulesModal()">&times;</button>
                </div>
                <div class="rules-content">
                    ${data.content}
                </div>
            </div>
        `;
        
        document.body.appendChild(modal);
        setTimeout(() => modal.classList.add('active'), 10);
        
        // Close on background click
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeRulesModal();
        });
    });
}

function closeRulesModal() {
    const modal = document.querySelector('.rules-modal');
    if (modal) {
        modal.classList.remove('active');
        setTimeout(() => modal.remove(), 300);
    }
}

// ========================================
// DAILY DECK MANAGEMENT
// ========================================

let currentDeckState = null;

function loadDailyDeck() {
    // Don't load daily deck if game isn't active
    if (!gameData || gameData.gameStatus !== 'active') {
        return Promise.resolve();
    }
    
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=get_daily_deck'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Check if deck state has changed
            const newDeckState = JSON.stringify(data.slots);
            if (currentDeckState !== newDeckState) {
                currentDeckState = newDeckState;
                dailyDeckData = data;
                dailyDeckData.remainingCards = data.remaining_cards || 0;
                updateDailyDeckDisplay(data.slots);
                setTimeout(() => updateCardModifiers(), 100);
                updateDeckMessage(data.slots);
            }
        } else {
            // Only try to generate if game is active
            if (gameData.gameStatus === 'active') {
                generateDailyDeck();
            }
        }
        updateDailyDeckCount();
    })
    .catch(error => {
        console.error('Error loading daily deck:', error);
    });
}

function checkDowntime() {
    // Skip if testing mode
    if (gameData.testingMode) {
        hideDowntimeOverlay();
        showGameClock();
        return false;
    }
    
    // Get current time in Indianapolis timezone
    const indianaTime = new Date().toLocaleString("en-US", {timeZone: "America/Indiana/Indianapolis"});
    const now = new Date(indianaTime);
    const hours = now.getHours();
    
    // Downtime is midnight (0) to 8am Indianapolis time
    if (hours >= 0 && hours < 8) {
        const nextAvailable = new Date(indianaTime);
        nextAvailable.setHours(8, 0, 0, 0);
        if(!isVetoWaiting) {
            showDowntimeOverlay(nextAvailable);
        }
        hideGameClock();
        return true;
    }
    
    hideDowntimeOverlay();
    showGameClock();
    return false;
}

function hideGameClock() {
    $('#dailyGameClock').addClass('downtime');
}

function showGameClock() {
    $('#dailyGameClock').removeClass('downtime');
}

function showDowntimeOverlay(nextAvailable) {
    hideDeckEmptyOverlay();
    const container = document.querySelector('.daily-deck-container');
    if (!container) return;
    
    container.classList.add('downtime');
    
    // Get day of week
    const indianaTime = new Date().toLocaleString("en-US", {timeZone: "America/Indiana/Indianapolis"});
    const now = new Date(indianaTime);
    const dayName = now.toLocaleDateString('en-US', { weekday: 'long' });
    
    let overlay = document.getElementById('downtimeOverlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'downtimeOverlay';
        overlay.className = 'downtime-overlay';
        overlay.innerHTML = `
            <div class="downtime-message">${dayName} Daily Deck Generates&nbsp;in</div>
            <div class="downtime-countdown" id="downtimeCountdown">0:00:00</div>
        `;
        container.appendChild(overlay);
    }
    
    overlay.style.display = 'flex';
    updateDowntimeCountdown(nextAvailable);
    
    const countdownInterval = setInterval(() => {
        if (!updateDowntimeCountdown(nextAvailable)) {
            clearInterval(countdownInterval);
            hideDowntimeOverlay();
            loadDailyDeck();
        }
    }, 1000);
}

function updateDowntimeCountdown(nextAvailable) {
    const countdown = document.getElementById('downtimeCountdown');
    if (!countdown) return false;
    
    // Get current time in Indianapolis timezone
    const indianaTime = new Date().toLocaleString("en-US", {timeZone: "America/Indiana/Indianapolis"});
    const now = new Date(indianaTime);
    const diff = nextAvailable - now;
    
    if (diff <= 0) {
        return false;
    }
    
    const hours = Math.floor(diff / (1000 * 60 * 60));
    const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
    const seconds = Math.floor((diff % (1000 * 60)) / 1000);

    $('.daily-deck-container').addClass('loaded');
    
    countdown.textContent = `${hours}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
    return true;
}

function hideDowntimeOverlay() {
    const container = document.querySelector('.daily-deck-container');
    const overlay = document.getElementById('downtimeOverlay');
    
    if (container) container.classList.remove('downtime');
    if (overlay) overlay.style.display = 'none';
}

function adjustScoreWithInput(playerId, multiplier) {
    const input = document.getElementById('scoreAdjustInput');
    const amount = parseInt(input.value);
    
    if (!amount || amount < 1) {
        alert('Please enter a valid amount (minimum 1)');
        return;
    }
    
    adjustScore(playerId, amount * multiplier);
    input.value = '';
}

function stealPointsWithInput(fromPlayerId, toPlayerId) {
    const input = document.getElementById('scoreAdjustInput');
    const amount = parseInt(input.value);
    
    if (!amount || amount < 1) {
        alert('Please enter a valid amount (minimum 1)');
        return;
    }
    
    stealPoints(fromPlayerId, toPlayerId, amount);
    input.value = '';
}

function updateCurseTimers() {
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=get_curse_timers'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            updateCurseTimerDisplay('playerCurseTimer', 'playerCurseTime', 'playerCurseCount', data.player_count, data.player_timer);
            updateCurseTimerDisplay('opponentCurseTimer', 'opponentCurseTime', 'opponentCurseCount', data.opponent_count, data.opponent_timer);
        }
    });
}

function updateCurseTimerDisplay(timerId, timeSpanId, countId, count, timerData) {
    const timer = document.getElementById(timerId);
    const timeSpan = document.getElementById(timeSpanId);
    const countSpan = document.getElementById(countId);
    
    // Guard: elements don't exist during onboarding
    if (!timer || !timeSpan) return;

    if(count > 1) {
        countSpan.textContent = count;
    } else {
        countSpan.textContent = '';
    }
    
    if (timerData && timerData.expires_at) {
        const now = new Date();
        const expires = new Date(timerData.expires_at);
        const diff = expires - now;
        
        if (diff > 0) {
            const minutes = Math.floor(diff / (1000 * 60));
            const seconds = Math.floor((diff % (1000 * 60)) / 1000);
            timeSpan.textContent = `${minutes}:${seconds.toString().padStart(2, '0')}`;
            timer.classList.add('active');
        } else {
            timer.classList.remove('active');
        }
    } else {
        timer.classList.remove('active');
    }
}

function generateDailyDeck() {
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=generate_daily_deck'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log('Daily deck generated');
            loadDailyDeck();
        }
    });
}

function updateDailyDeckDisplay(slots) {
    const dailySlots = document.querySelectorAll('.daily-slot');
    
    slots.forEach((slot, index) => {
        const slotElement = dailySlots[index];
        if (!slotElement) return;
        
        const slotContent = slotElement.querySelector('.slot-content');
        if (!slotContent) return;
        
        if (slot.card_id) {
            slotElement.classList.add('has-card');
            slotContent.innerHTML = createSlotCardHTML(slot);

            // Add curse-activated class if curse has been activated
            if (slot.card_category === 'curse' && slot.curse_activated) {
                slotElement.classList.add('curse-activated');
            } else {
                slotElement.classList.remove('curse-activated');
            }

            if (slot.card_category === 'curse' && !slot.curse_activated) {
                setTimeout(() => startCurseAutoActivate(slot.slot_number), 100);
            }
        } else {
            slotElement.classList.remove('has-card', 'curse-activated');
            // Check if there are cards remaining to draw
            if (dailyDeckData.remainingCards > 0) {
                slotElement.classList.remove('disabled');
                slotContent.innerHTML = '<div class="empty-slot">TAP TO DRAW A CARD</div>';
            } else {
                slotElement.classList.add('disabled');
                slotContent.innerHTML = '';
            }
        }
    });

    const container = document.querySelector('.daily-deck-container');
    if (container) container.classList.add('loaded');
}

function updateDailyDeckCount() {
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=get_daily_deck_count'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const countEl = document.getElementById('deckCountText');
            const containerEl = document.getElementById('dailyDeckCount');
            if (countEl && containerEl && data.remaining > 0) {
                countEl.textContent = `Cards Remaining: ${data.remaining}`;
            }
            if(countEl && containerEl && data.remaining == 0) {
                countEl.textContent = 'No Cards Remaining';

            }
        }
    });
}

function updateDailyGameClock() {
    const clockElement = document.getElementById('dailyGameClock');
    if (!clockElement || !gameData || gameData.gameStatus !== 'active' || !gameData.startDate) return;
    
    const timezone = 'America/Indiana/Indianapolis';
    const now = new Date();
    const indianaTime = new Date(now.toLocaleString("en-US", {timeZone: timezone}));
    
    // Calculate day of game
    const startDate = new Date(gameData.startDate.replace(' ', 'T'));
    const daysSinceStart = Math.floor((indianaTime - startDate) / (1000 * 60 * 60 * 24));
    const gameDay = daysSinceStart + 1;
    
    // Calculate time until midnight
    const endOfDay = new Date(indianaTime);
    endOfDay.setHours(23, 59, 59, 999);
    const diff = endOfDay - indianaTime;
    
    if (diff <= 0) {
        clockElement.querySelector('.clock-time').textContent = '00:00';
        return;
    }
    
    const hours = Math.floor(diff / (1000 * 60 * 60));
    const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
    const seconds = Math.floor((diff % (1000 * 60)) / 1000);
    
    // Format based on time remaining
    let timeText;
    if (hours > 0) {
        timeText = `${hours}:${minutes.toString().padStart(2, '0')}`;
    } else {
        timeText = `${minutes}:${seconds.toString().padStart(2, '0')}`;
    }
    
    clockElement.querySelector('.game-day').textContent = `Day ${gameDay}`;
    clockElement.querySelector('.clock-time').textContent = timeText;
}

function animateCardDraw(slotElement) {
    const cardContent = slotElement.querySelector('.card-content');
    if (cardContent) {
        cardContent.classList.add('card-draw-animation');
        setTimeout(() => {
            cardContent.classList.remove('card-draw-animation');
            slotElement.classList.remove('loading');
        }, 1200);
    }
}

function animateCardComplete(slotElement) {
    const cardContent = slotElement.querySelector('.card-content, .hand-slot-card');
    if (cardContent) {
        cardContent.style.transition = 'all 1s cubic-bezier(0.34, 1.56, 0.64, 1)';
        cardContent.style.transform = 'scale(0)';
        cardContent.style.opacity = '0';
    }
}

function animateCardVeto(slotElement) {
    const cardContent = slotElement.querySelector('.card-content, .hand-slot-card');
    if (cardContent) {
        cardContent.style.transition = 'all 0.6s cubic-bezier(0.6, 0.04, 0.98, 0.34)';
        cardContent.style.transform = 'translateY(200vh) rotate(25deg)';
        cardContent.style.opacity = '0';
    }
}

function animateHandCardIn(slotElement) {
    const cardContent = slotElement.querySelector('.hand-slot-card');
    if (cardContent) {
        cardContent.classList.add('card-draw-animation');
        setTimeout(() => {
            slotElement.classList.remove('loading');
            cardContent.classList.remove('card-draw-animation');
        }, 1200);
    }
}

function createSlotCardHTML(slot) {
    const cardTypeIcons = {
        'challenge': 'fa-flag-checkered',
        'curse': 'fa-skull-crossbones',
        'power': 'fa-star',
        'battle': 'fa-swords'
    };
    
    const icon = cardTypeIcons[slot.card_category] || 'fa-square';
    
    let badges = '';
    if (slot.card_points) {
        badges += `<div class="card-badge points">+${slot.card_points}</div>`;
    }
    if (slot.timer && slot.card_category === 'curse') {
        badges += `<div class="card-badge timer">${slot.timer}m</div>`;
    }
    if (slot.veto_subtract) {
        badges += `<div class="card-badge penalty">-${slot.veto_subtract}</div>`;
    }
    if (slot.veto_steal) {
        badges += `<div class="card-badge penalty"><i class="fa-solid fa-hand"></i> ${slot.veto_steal}</div>`;
    }
    if (slot.veto_wait) {
        badges += `<div class="card-badge penalty"><i class="fa-solid fa-circle-pause"></i> ${slot.veto_wait}</div>`;
    }
    if (slot.veto_snap) {
        badges += `<div class="card-badge penalty"><i class="fa-solid fa-camera-retro"></i> ${slot.veto_snap}</div>`;
    }
    if (slot.veto_spicy) {
        badges += `<div class="card-badge penalty"><i class="fa-solid fa-pepper-hot"></i> ${slot.veto_spicy}</div>`;
    }
    
    // Add auto-activate message for curse cards
    if (slot.card_category === 'curse' && !slot.curse_activated) {
        badges += `<div class="card-badge auto-activate" id="curse-auto-${slot.slot_number}">Activating in 10s...</div>`;
    }
    
    const actions = getSlotActions(slot.card_category);
    
    // For curse cards, don't show actions if already activated
    const actionsHTML = (slot.card_category === 'curse' && slot.curse_activated) ? '' : `
        <div class="slot-actions">
            ${actions.map(action => `
                <button class="slot-action-btn ${action.class || ''}" onclick="${action.onClick}(${slot.slot_number || 1})">
                    ${action.text}
                </button>
            `).join('')}
        </div>
    `;
    
    return `
        <div class="card-content">
            <div class="card-header">
                <div class="card-type-icon ${slot.card_category}">
                    <i class="fa-solid ${icon}"></i>
                </div>
                <div class="card-info">
                    <div class="card-name">${slot.card_name}</div>
                </div>
            </div>
            <div class="card-description">${slot.card_description}</div>
            <div class="card-meta">
                ${badges}
            </div>
        </div>
        ${actionsHTML}
    `;
}

function preserveSlotHeight(slotNumber) {
    const slots = document.querySelectorAll('.daily-slot');
    const slot = slots[slotNumber - 1];
    
    if (slot) {
        const currentHeight = slot.offsetHeight;
        slot.style.minHeight = `${currentHeight}px`;
        
        // Remove min-height after empty slot loads
        setTimeout(() => {
            slot.style.minHeight = '';
        }, 100);
    }
}

function updateDeckMessage(slots) {
    const overlay = document.getElementById('deckMessageOverlay');
    if (!overlay) return;
    
    const emptySlots = slots.filter(slot => !slot.card_id).length;
    const hasRemainingCards = dailyDeckData.remainingCards > 0;
    
    // Only show if all slots are empty AND there are cards to draw
    overlay.style.display = (emptySlots === 3 && hasRemainingCards) ? 'flex' : 'none';
}

function drawAllSlots() {
    drawToSlot(1);
    setTimeout(() => drawToSlot(2), 1600);
    setTimeout(() => drawToSlot(3), 3200);
}

function startCurseAutoActivate(slotNumber) {
    console.log('start auto activate countdown called');
    if(autoCurseInterval) {
        clearInterval(autoCurseInterval);
        autoCurseInterval = null;
    }
    let countdown = 10; // Changed from 5 to 10 seconds
    const badge = document.getElementById(`curse-auto-${slotNumber}`);
    
    autoCurseInterval = setInterval(() => {
        countdown--;
        if (badge) badge.textContent = `Activating in ${countdown}s...`;
        
        if (countdown <= 0) {
            clearInterval(autoCurseInterval);
            if (badge) badge.remove();
            activateCurse(slotNumber);
        }
    }, 1000);
}

// ========================================
// SLOT INTERACTIONS
// ========================================

function handleSlotInteraction(slotNumber) {
    if (isVetoWaiting) {
        showInAppNotification('Blocked', 'Cannot interact during veto wait period');
        return;
    }
    
    const slot = dailyDeckData.slots[slotNumber - 1];
    
    if (!slot || !slot.card_id) {
        drawToSlot(slotNumber);
    } else {
        // Check if card has any actions
        const actions = getSlotActions(slot.card_category);
        
        // For curse cards that are already activated, no actions
        if (slot.card_category === 'curse' && slot.curse_activated) {
            return; // Do nothing
        }
        
        // If there are actions, toggle expansion
        if (actions.length > 0) {
            toggleSlotActions(slotNumber);
        }
    }
}

function toggleSlotActions(slotNumber) {
    const slotElement = document.querySelector(`.daily-slot[data-slot="${slotNumber}"]`);
    
    // Close other expanded slots first
    document.querySelectorAll('.daily-slot.expanded').forEach(slot => {
        if (slot !== slotElement) {
            slot.classList.remove('expanded');
        }
    });
    
    // Toggle this slot
    slotElement.classList.toggle('expanded');
}

function drawToSlot(slotNumber) {
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=draw_to_slot&slot_number=${slotNumber}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            playSoundIfEnabled('/card-drawn.m4r');
            // Add loading class to prevent flash
            const slotEl = document.querySelector(`.daily-slot[data-slot="${slotNumber}"]`);
            if (slotEl) slotEl.classList.add('loading');
            loadDailyDeck();
            setTimeout(() => {
                const slotEl = document.querySelector(`.daily-slot[data-slot="${slotNumber}"]`);
                if (slotEl && slotEl.classList.contains('loading')) {
                    animateCardDraw(slotEl);
                }
            }, 200);
            updateDailyDeckCount();
            setTimeout(() => updateCardModifiers(), 200);
        } else {
            alert('Failed to draw card: ' + (data.message || 'Unknown error'));
        }
    });
}

function getSlotActions(cardCategory) {
    switch (cardCategory) {
        case 'challenge':
            return [
                { text: 'Complete', onClick: 'completeChallenge' },
                { text: 'Veto', onClick: 'vetoChallenge', class: 'btn-secondary' }
            ];
        case 'curse':
            return [
                { text: 'Activate', onClick: 'activateCurse' }
            ];
        case 'power':
            return [
                { text: 'Claim', onClick: 'claimPower' },
                { text: 'Activate', onClick: 'activatePower' },
                { text: 'Discard', onClick: 'discardPower', class: 'btn-secondary' }
            ];
        case 'battle':
            return [
                { text: 'Win', onClick: 'winBattle' },
                { text: 'Lose', onClick: 'loseBattle', class: 'btn-secondary' }
            ];
        default:
            return [];
    }
}

function updateSlotActionsForModifiers() {
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=get_active_modifiers'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const hasSkipPower = data.modifiers.some(m => m.skip_challenge == 1);
            const hasBypassPower = data.modifiers.some(m => m.bypass_expiration == 1);
            
            document.querySelectorAll('.daily-slot').forEach(slotEl => {
                const cardContent = slotEl.querySelector('.card-content');
                if (!cardContent) return;
                
                const challengeIcon = cardContent.querySelector('.card-type-icon.challenge');
                if (challengeIcon) {
                    let actions = slotEl.querySelector('.slot-actions');
                    if (!actions) return;
                    
                    const slotNumber = parseInt(slotEl.dataset.slot);
                    
                    // Remove old power buttons
                    actions.querySelectorAll('.skip-btn, .store-btn').forEach(btn => btn.remove());
                    
                    // Add skip button
                    if (hasSkipPower) {
                        const skipBtn = document.createElement('button');
                        skipBtn.className = 'slot-action-btn skip-btn';
                        skipBtn.textContent = 'Skip';
                        skipBtn.onclick = (e) => {
                            e.stopPropagation();
                            skipChallenge(slotNumber);
                            setTimeout(() => updateSlotActionsForModifiers(), 500);
                        };
                        actions.appendChild(skipBtn);
                    }
                    
                    // Add store button
                    if (hasBypassPower) {
                        const storeBtn = document.createElement('button');
                        storeBtn.className = 'slot-action-btn store-btn';
                        storeBtn.textContent = 'Store';
                        storeBtn.onclick = (e) => {
                            e.stopPropagation();
                            storeChallenge(slotNumber);
                            setTimeout(() => updateSlotActionsForModifiers(), 500);
                        };
                        actions.appendChild(storeBtn);
                    }
                }
            });
        }
    });
}

function storeChallenge(slotNumber) {
    const slotElement = document.querySelector(`.daily-slot[data-slot="${slotNumber}"]`);
    
    // Start animation immediately
    if (slotElement) {
        animateCardToHand(slotElement);
    }
    
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=store_challenge&slot_number=${slotNumber}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showInAppNotification('Challenge Stored!', data.message);
            loadDailyDeck();
            loadHandCards();
            $('.daily-slot[data-slot="' + slotNumber + '"]').removeClass('expanded');
        } else {
            alert('Failed to store challenge: ' + data.message);
            // Restore card visibility if failed
            if (slotElement) {
                const cardContent = slotElement.querySelector('.card-content');
                if (cardContent) {
                    cardContent.style.opacity = '1';
                }
            }
        }
    });
}

function skipChallenge(slotNumber) {
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=skip_challenge&slot_number=${slotNumber}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            playSoundIfEnabled('/card-completed.m4r');
            showInAppNotification('Challenge Skipped!', data.message);
            loadDailyDeck();
        } else {
            alert('Failed to skip challenge: ' + data.message);
        }
    });
}

function animateCardToHand(slotElement) {
    const cardContent = slotElement.querySelector('.card-content');
    const gameTimerHand = document.querySelector('.game-timer-hand');
    
    if (!cardContent || !gameTimerHand) {
        return;
    }
    
    // Get initial positions
    const cardRect = cardContent.getBoundingClientRect();
    const handRect = gameTimerHand.getBoundingClientRect();
    
    // Calculate movement distances
    const deltaX = handRect.left + (handRect.width / 2) - (cardRect.left + (cardRect.width / 2));
    const deltaY = handRect.top + (handRect.height / 2) - (cardRect.top + (cardRect.height / 2));
    
    // Create clone for animation
    const cardClone = cardContent.cloneNode(true);
    cardClone.style.position = 'fixed';
    cardClone.style.top = cardRect.top + 'px';
    cardClone.style.left = cardRect.left + 'px';
    cardClone.style.width = cardRect.width + 'px';
    cardClone.style.height = cardRect.height + 'px';
    cardClone.style.zIndex = '9999';
    cardClone.style.pointerEvents = 'none';
    cardClone.style.transition = 'all 0.8s cubic-bezier(0.25, 0.46, 0.45, 0.94)';
    cardClone.style.transformOrigin = 'center center';
    
    // Hide original card
    cardContent.style.opacity = '0';
    
    // Add clone to body
    document.body.appendChild(cardClone);
    
    // Start animation on next frame
    requestAnimationFrame(() => {
        cardClone.style.transform = `translate(${deltaX}px, ${deltaY}px) scale(0)`;
        cardClone.style.opacity = '0';
    });
    
    // Clean up after animation
    setTimeout(() => {
        if (cardClone.parentNode) {
            cardClone.parentNode.removeChild(cardClone);
        }
    }, 800);
}

// Close slot actions when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('.daily-slot')) {
        document.querySelectorAll('.daily-slot.expanded').forEach(slot => {
            slot.classList.remove('expanded');
        });
    }

    if (handOverlayOpen && 
        !e.target.closest('#handOverlay') && 
        !e.target.closest('#gameTimerHand')) {
        toggleHandOverlay();
    }
});

function setBodyClass(className, shouldAdd) {
    if (shouldAdd) {
        document.body.classList.add(className);
    } else {
        document.body.classList.remove(className);
    }
}

// ========================================
// CARD ACTIONS
// ========================================

function updateCardModifiers() {
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=get_active_modifiers'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            addModifierBadges(data.modifiers);
            // Check for veto skip power and update body class
            const hasVetoSkip = data.modifiers.some(m => m.veto_modify === 'skip');
            setBodyClass('no-veto', hasVetoSkip);
        }
    });
}

function addModifierBadges(modifiers) {
    // Clean up badges that no longer have active modifiers
    cleanupOrphanedBadges(modifiers);
    
    modifiers.forEach(modifier => {
        const cardTypes = [];
        if (modifier.challenge_modify) cardTypes.push('challenge');
        if (modifier.snap_modify) cardTypes.push('snap');
        if (modifier.spicy_modify) cardTypes.push('spicy');
        if (modifier.skip_challenge) cardTypes.push('challenge');
        
        cardTypes.forEach(cardType => {
            // Add badges to daily deck cards
            document.querySelectorAll(`.card-type-icon.${cardType}`).forEach(icon => {
                const cardElement = icon.closest('.card-content');
                if (cardElement && !cardElement.querySelector('.modifier-badge')) {
                    addBadgeToCard(cardElement, modifier);
                }
            });
            
            // Add badges to hand cards
            document.querySelectorAll(`.hand-slot-card-type-icon.${cardType}`).forEach(icon => {
                const cardElement = icon.closest('.hand-slot-card');
                if (cardElement && !cardElement.querySelector('.modifier-badge')) {
                    addBadgeToCard(cardElement, modifier);
                }
            });
        });
    });
}

function cleanupOrphanedBadges(activeModifiers) {
    document.querySelectorAll('.modifier-badge').forEach(badge => {
        const cardElement = badge.closest('.card-content, .hand-slot-card');
        if (!cardElement) {
            badge.remove();
            return;
        }
        
        // Check if this badge's modifier is still active
        const badgeText = badge.textContent.trim();
        const isStillActive = activeModifiers.some(modifier => 
            badgeText.includes(modifier.card_name)
        );
        
        if (!isStillActive) {
            badge.remove();
        }
    });
}

function addBadgeToCard(cardElement, modifier) {
    if (!cardElement || cardElement.querySelector('.modifier-badge')) return;
    
    const badge = document.createElement('div');
    badge.className = `modifier-badge ${modifier.type}`;
    
    // Handle veto_modify badges
    if (modifier.veto_modify && modifier.veto_modify !== 'none') {
        badge.innerHTML = `
            <i class="fa-solid ${modifier.type === 'curse' ? 'fa-skull-crossbones' : 'fa-star'}"></i>
            ${modifier.card_name}
        `;
    } else {
        badge.innerHTML = `
            <i class="fa-solid ${modifier.type === 'curse' ? 'fa-skull-crossbones' : 'fa-star'}"></i>
            ${modifier.card_name}
        `;
    }
    
    cardElement.style.position = 'relative';
    cardElement.appendChild(badge);
}

function completeChallenge(slotNumber) {
    const slotEl = document.querySelector(`.daily-slot[data-slot="${slotNumber}"]`);
    
    playSoundIfEnabled('/card-completed.m4r');
    animateCardComplete(slotEl);
    forceRefreshOnce();
    
    setTimeout(() => {
        fetch('game.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=complete_challenge&slot_number=${slotNumber}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                preserveSlotHeight(slotNumber);
                loadDailyDeck();
            } else {
                alert('Failed to complete challenge: ' + data.message);
            }
        });
    }, 1000);
}

function vetoChallenge(slotNumber) {
    const slotEl = document.querySelector(`.daily-slot[data-slot="${slotNumber}"]`);
    
    playSoundIfEnabled('/card-vetoed.m4r');
    animateCardVeto(slotEl);
    
    setTimeout(() => {
        fetch('game.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=veto_challenge&slot_number=${slotNumber}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                preserveSlotHeight(slotNumber);
                loadDailyDeck();
                forceRefreshOnce();
                checkVetoWait();
                setTimeout(() => { updateHandSlots(), 1000});
            } else {
                alert('Failed to veto challenge: ' + data.message);
            }
        });
    }, 600);
}

function completeStoredChallenge(playerCardId) {
    const cardSlot = document.querySelector(`[onclick*="completeStoredChallenge(${playerCardId})"]`)?.closest('.hand-slot');
    
    if (cardSlot) {
        playSoundIfEnabled('/card-completed.m4r');
        animateCardComplete(cardSlot);
        forceRefreshOnce();
    }
    
    setTimeout(() => {
        fetch('game.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=complete_stored_challenge&player_card_id=${playerCardId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                loadHandCards();
            } else {
                alert('Failed to complete challenge: ' + data.message);
            }
        });
    }, 1000);
}

function vetoStoredChallenge(playerCardId) {
    const cardSlot = document.querySelector(`[onclick*="vetoStoredChallenge(${playerCardId})"]`)?.closest('.hand-slot');
    
    if (cardSlot) {
        playSoundIfEnabled('/card-vetoed.m4r');
        animateCardVeto(cardSlot);
        forceRefreshOnce();
    }
    
    setTimeout(() => {
        fetch('game.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=veto_stored_challenge&player_card_id=${playerCardId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                loadHandCards();
                checkVetoWait();
            } else {
                alert('Failed to veto challenge: ' + data.message);
            }
        });
    }, 600);
}

function activateCurse(slotNumber) {

    // Get card data to check if dice roll needed
    const slot = dailyDeckData.slots[slotNumber - 1];
    // Handle dice roll requirement BEFORE backend call
    if (slot && slot.roll_dice) {
        setTimeout(() => {
            openDicePopover((die1, die2, total) => {
                // Check dice condition on frontend
                const cleared = checkDiceCondition(slot.dice_condition, slot.dice_threshold, die1, die2, total);
                
                // Call backend with result
                fetch('game.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=activate_curse&slot_number=${slotNumber}&dice_result=${cleared ? 'cleared' : 'activated'}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        playSoundIfEnabled('/card-curse.m4r');
                        showInAppNotification(cleared ? 'Curse Cleared!' : 'Curse Activated!', data.message);
                        // Animate card content out before removing
                        const slot = document.querySelector(`.daily-slot[data-slot="${slotNumber}"]`);
                        const cardContent = slot?.querySelector('.card-content');
                        if (cardContent) {
                            cardContent.style.transition = 'opacity 0.7s ease, transform 0.7s ease';
                            cardContent.style.opacity = '0';
                            cardContent.style.transform = 'scale(3)';
                            
                            setTimeout(() => {
                                loadDailyDeck();
                            }, 700);
                        } else {
                            loadDailyDeck();
                        }
                        setTimeout(() => updateStatusEffects(), 1000);
                    }
                });
            });
        }, 500);
        return;
    }

    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=activate_curse&slot_number=${slotNumber}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            playSoundIfEnabled('/card-curse.m4r');
            console.log(data);
            
            // Animate card content out before removing
            const slot = document.querySelector(`.daily-slot[data-slot="${slotNumber}"]`);
            const cardContent = slot?.querySelector('.card-content');
            if (cardContent) {
                cardContent.style.transition = 'opacity 0.7s ease, transform 0.7s ease';
                cardContent.style.opacity = '0';
                cardContent.style.transform = 'scale(3)';
                
                setTimeout(() => {
                    loadDailyDeck();
                }, 700);
            } else {
                loadDailyDeck();
            }
            setTimeout(() => updateStatusEffects(), 1000);
        } else {
            alert('Failed to activate curse: ' + data.message);
        }
    });
}

function checkDiceCondition(condition, threshold, die1, die2, total) {
    if (!condition) return false;
    
    switch (condition) {
        case 'above':
            return total > threshold;
        case 'below':
            return total < threshold;
        case 'even':
            return die1 % 2 === 0 && die2 % 2 === 0;
        case 'odd':
            return die1 % 2 !== 0 && die2 % 2 !== 0;
        case 'doubles':
            return die1 === die2;
        default:
            return false;
    }
}

function claimPower(slotNumber) {
    const slotElement = document.querySelector(`.daily-slot[data-slot="${slotNumber}"]`);
    
    // Start animation immediately
    if (slotElement) {
        animateCardToHand(slotElement);
    }
    
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=claim_power&slot_number=${slotNumber}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            preserveSlotHeight(slotNumber);
            loadDailyDeck();
            setTimeout(() => loadHandCards(), 1000);
        } else {
            alert('Failed to claim power: ' + data.message);
            // Restore card visibility if failed
            if (slotElement) {
                const cardContent = slotElement.querySelector('.card-content');
                if (cardContent) {
                    cardContent.style.opacity = '1';
                }
            }
        }
    });
}

function activatePower(slotNumber) {
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=activate_power&slot_number=${slotNumber}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            playSoundIfEnabled('/card-power.m4r');
            // Animate card content out before removing
            const slot = document.querySelector(`.daily-slot[data-slot="${slotNumber}"]`);
            const cardContent = slot?.querySelector('.card-content');
            if (cardContent) {
                cardContent.style.transition = 'opacity 0.7s ease, transform 0.7s ease';
                cardContent.style.opacity = '0';
                cardContent.style.transform = 'scale(3)';
                
                setTimeout(() => {
                    loadDailyDeck();
                }, 700);
            } else {
                loadDailyDeck();
            }
            setTimeout(() => updateStatusEffects(), 1000);
        } else {
            alert('Failed to activate power: ' + data.message);
        }
    });
}

function discardPower(slotNumber) {
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=discard_power&slot_number=${slotNumber}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            playSoundIfEnabled('/card-vetoed.m4r');
            preserveSlotHeight(slotNumber);
            loadDailyDeck();
        } else {
            alert('Failed to discard power: ' + data.message);
        }
    });
}

function winBattle(slotNumber) {
    completeBattle(slotNumber, true);
}

function loseBattle(slotNumber) {
    completeBattle(slotNumber, false);
}

function completeBattle(slotNumber, isWinner) {
    const slotEl = document.querySelector(`.daily-slot[data-slot="${slotNumber}"]`);
    
    playSoundIfEnabled('/card-completed.m4r');
    animateCardComplete(slotEl);
    forceRefreshOnce();
    
    setTimeout(() => {
        fetch('game.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=complete_battle&slot_number=${slotNumber}&is_winner=${isWinner}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                preserveSlotHeight(slotNumber);
                loadDailyDeck();
            } else {
                alert('Failed to complete battle: ' + data.message);
            }
        });
    }, 1000);
}

// ========================================
// HAND MANAGEMENT
// ========================================

function updateDeckCounts() {
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=get_deck_counts'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const snapCount = document.querySelector('.snap-deck .deck-count');
            const spicyCount = document.querySelector('.spicy-deck .deck-count');
            
            if (snapCount) snapCount.textContent = `${data.snap_count} cards available`;
            if (spicyCount) spicyCount.textContent = `${data.spicy_count} cards available`;
        }
    });
}

function toggleHandOverlay(event) {
    if (event) {
        event.stopPropagation();
        event.preventDefault();

        // Close if clicking background (hand-overlay) but not deck-selector or hand-slots
        if (event.target.classList.contains('hand-overlay')) {
            if (handOverlayOpen) {
                handOverlayOpen = false;
                const overlay = document.getElementById('handOverlay');
                overlay.classList.remove('active');
                setOverlayActive(false);
                return;
            }
        }
    }
    const overlay = document.getElementById('handOverlay');
    if (!overlay) return;
    
    handOverlayOpen = !handOverlayOpen;
    
    if (handOverlayOpen) {
        loadHandCards();
        overlay.classList.add('active');
        setOverlayActive(true);
        setTimeout(() => updateCardModifiers(), 100);
    } else {
        overlay.classList.remove('active');
        setOverlayActive(false);
    }
}

function loadHandCards() {
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=get_player_hand'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            handCards = data.hand;
            
            // Only update hand slots if none are expanded
            const hasExpandedSlots = document.querySelectorAll('.hand-slot.expanded').length > 0;
            if (!hasExpandedSlots) {
                updateHandSlots();
            }
            
            updateDeckCounts();
        }
    });
}

function createHandCardElement(card) {
    const div = document.createElement('div');
    div.className = 'hand-card';
    div.onclick = () => showHandCardActions(card);
    
    let badges = '';
    if (card.effective_points) {
        badges += `<div class="card-badge points">+${card.effective_points}</div>`;
    }
    if (card.veto_subtract) {
        badges += `<div class="card-badge penalty">-${card.veto_subtract}</div>`;
    }
    if (card.quantity > 1) {
        badges += `<div class="card-badge quantity">${card.quantity}x</div>`;
    }
    
    div.innerHTML = `
        <div class="card-header">
            <div class="card-type">${getCardTypeIcon(card.card_type)}</div>
            <div class="card-name">${card.card_name}</div>
        </div>
        <div class="card-description">${card.card_description}</div>
        <div class="card-meta">${badges}</div>
    `;
    
    return div;
}

function getCardTypeIcon(type) {
    const icons = {
        'stored_challenge': '<i class="fa-solid fa-flag-checkered"></i> Challenge',
        'challenge': '<i class="fa-solid fa-flag-checkered"></i> Challenge',
        'power': '<i class="fa-solid fa-bolt"></i> Power',
        'snap': '<i class="fa-solid fa-camera-retro"></i> Snap',
        'spicy': '<i class="fa-solid fa-pepper-hot"></i> Spicy'
    };
    return icons[type] || type;
}

function showHandCardActions(card) {
    const actions = getHandCardActions(card.card_type);
    
    const modal = document.createElement('div');
    modal.className = 'modal active';
    modal.innerHTML = `
        <div class="modal-content">
            <div class="modal-title">${card.card_name}</div>
            <div class="modal-subtitle">${card.card_description}</div>
            ${actions.map(action => `
                <button class="btn ${action.class || ''}" onclick="${action.onClick}(${card.id}); closeHandCardActions()">
                    ${action.text}
                </button>
            `).join('')}
            <button class="btn btn-secondary" onclick="closeHandCardActions()">Cancel</button>
        </div>
    `;
    
    document.body.appendChild(modal);
    modal.id = 'handCardActionsModal';
    setOverlayActive(true);
}

function getHandCardActions(cardType) {
    switch (cardType) {
        case 'stored_challenge':
            return [
                { text: 'Complete', onClick: 'completeStoredChallenge' },
                { text: 'Veto', onClick: 'vetoStoredChallenge', class: 'btn-secondary' }
            ];
        case 'power':
            return [
                { text: 'Activate', onClick: 'playPowerCard' }
            ];
        case 'snap':
            return [
                { text: 'Complete', onClick: 'completeSnapCard' },
                { text: 'Veto', onClick: 'vetoSnapCard', class: 'btn-secondary' }
            ];
        case 'spicy':
            return [
                { text: 'Complete', onClick: 'completeSpicyCard' },
                { text: 'Veto', onClick: 'vetoSpicyCard', class: 'btn-secondary' }
            ];
        default:
            return [];
    }
}

function closeHandCardActions() {
    const modal = document.getElementById('handCardActionsModal');
    if (modal) {
        modal.remove();
        setOverlayActive(false);
    }
}

function checkForDeckPeek() {
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=get_active_modifiers'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.modifiers.some(m => m.deck_peek)) {
            showDeckPeekButton();
        }
    });
}

function showDeckPeekButton() {
    const deckContainer = document.querySelector('.daily-deck-container');
    if (!deckContainer.querySelector('.peek-btn')) {
        const peekBtn = document.createElement('button');
        peekBtn.className = 'btn peek-btn';
        peekBtn.textContent = '👁️ Peek Deck';
        peekBtn.style.marginBottom = '20px';
        peekBtn.onclick = peekDeck;
        deckContainer.insertBefore(peekBtn, deckContainer.firstChild);
    }
}

function peekDeck() {
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=peek_deck'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showDeckPeekOverlay(data.cards, data.power_name);
            document.querySelector('.peek-btn')?.remove();
        } else {
            alert('Failed to peek deck: ' + data.message);
        }
    });
}

function showDeckPeekOverlay(cards, powerName) {
    const overlay = document.createElement('div');
    overlay.className = 'modal active';
    overlay.innerHTML = `
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-title">Deck Peek - ${powerName}</div>
            <div class="modal-subtitle">Next 3 cards in your daily deck:</div>
            <div style="display: flex; flex-direction: column; gap: 15px; margin: 20px 0;">
                ${cards.map(card => `
                    <div style="display: flex; align-items: center; gap: 15px; padding: 15px; background: #f8f9fa; border-radius: 10px;">
                        <div class="card-type-icon ${card.card_category}" style="transform: none;">
                            <i class="fa-solid ${getCardTypeIconClass(card.card_category)}"></i>
                        </div>
                        <div>
                            <div style="font-weight: 700; margin-bottom: 5px; color: #3c3c3c;">${card.card_name}</div>
                            <div style="font-size: 12px; color: #666;">${card.card_description}</div>
                        </div>
                    </div>
                `).join('')}
            </div>
            <button class="btn" onclick="this.closest('.modal').remove()">Close</button>
        </div>
    `;
    document.body.appendChild(overlay);
}

// Hand card action functions
function playPowerCard(playerCardId) {
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=play_power_card&player_card_id=${playerCardId}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            playSoundIfEnabled('/card-power.m4r');
            // Animate card content out before removing
            const cardSlot = document.querySelector(`[onclick*="playPowerCard(${playerCardId})"]`)?.closest('.hand-slot');
            const cardContent = cardSlot?.querySelector('.hand-slot-card');
            if (cardContent) {
                cardContent.style.transition = 'opacity 0.7s ease, transform 0.7s ease';
                cardContent.style.opacity = '0';
                cardContent.style.transform = 'scale(3)';
                
                setTimeout(() => {
                    loadHandCards();
                }, 700);
            } else {
                loadHandCards();
            }
            setTimeout(() => updateStatusEffects(), 1000);
        } else {
            alert('Failed to play power card: ' + data.message);
        }
    });
}

function completeSnapCard(playerCardId) {
    const cardSlot = document.querySelector(`[onclick*="completeSnapCard(${playerCardId})"]`)?.closest('.hand-slot');
    
    if (cardSlot) {
        playSoundIfEnabled('/card-completed.m4r');
        animateCardComplete(cardSlot);
    }
    
    setTimeout(() => {
        fetch('game.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=complete_snap_card&player_card_id=${playerCardId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (data.points_awarded) {
                    setTimeout(() => updateScore(gameData.currentPlayerId, data.points_awarded), 500);
                }
                loadHandCards();
                setTimeout(() => updateStatusEffects(), 1000);
            } else {
                alert('Failed to complete snap card: ' + data.message);
            }
        });
    }, 1000);
}

function completeSpicyCard(playerCardId) {
    const cardSlot = document.querySelector(`[onclick*="completeSpicyCard(${playerCardId})"]`)?.closest('.hand-slot');
    
    if (cardSlot) {
        playSoundIfEnabled('/card-completed.m4r');
        animateCardComplete(cardSlot);
    }
    
    setTimeout(() => {
        fetch('game.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=complete_spicy_card&player_card_id=${playerCardId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (data.points_awarded) {
                    setTimeout(() => updateScore(gameData.currentPlayerId, data.points_awarded), 500);
                }
                loadHandCards();
                setTimeout(() => updateStatusEffects(), 1000);
            } else {
                alert('Failed to complete spicy card: ' + data.message);
            }
        });
    }, 1000);
}

function vetoSnapCard(playerCardId) {
    const cardSlot = document.querySelector(`[onclick*="vetoSnapCard(${playerCardId})"]`)?.closest('.hand-slot');
    
    if (cardSlot) {
        playSoundIfEnabled('/card-vetoed.m4r');
        animateCardVeto(cardSlot);
    }
    
    setTimeout(() => {
        fetch('game.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=veto_snap_card&player_card_id=${playerCardId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                loadHandCards();
                checkVetoWait();
            } else {
                alert('Failed to veto snap card: ' + data.message);
            }
        });
    }, 600);
}

function vetoSpicyCard(playerCardId) {
    const cardSlot = document.querySelector(`[onclick*="vetoSpicyCard(${playerCardId})"]`)?.closest('.hand-slot');
    
    if (cardSlot) {
        playSoundIfEnabled('/card-vetoed.m4r');
        animateCardVeto(cardSlot);
    }
    
    setTimeout(() => {
        fetch('game.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=veto_spicy_card&player_card_id=${playerCardId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                loadHandCards();
                checkVetoWait();
            } else {
                alert('Failed to veto spicy card: ' + data.message);
            }
        });
    }, 600);
}

function drawSnapCard() {
    playSoundIfEnabled('/card-drawn.m4r');
    
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=draw_snap_card'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Calculate which slot will get the new card
            const currentCardCount = handCards.reduce((sum, card) => sum + card.quantity, 0);
            const newCardSlotIndex = currentCardCount; // Next available slot
            const slots = document.querySelectorAll('.hand-slot');
            const targetSlot = slots[newCardSlotIndex];
            if (targetSlot) targetSlot.classList.add('loading');

            loadHandCards();
            setTimeout(() => {
                const updatedSlots = document.querySelectorAll('.hand-slot');
                const newSlot = updatedSlots[newCardSlotIndex];
                if (newSlot && newSlot.classList.contains('filled')) {
                    animateHandCardIn(newSlot);
                }
            }, 200);
        } else {
            alert('Failed to draw snap card: ' + data.message);
        }
    });
}

function drawSpicyCard() {
    playSoundIfEnabled('/card-drawn.m4r');
    
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=draw_spicy_card'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Calculate which slot will get the new card
            const currentCardCount = handCards.reduce((sum, card) => sum + card.quantity, 0);
            const newCardSlotIndex = currentCardCount; // Next available slot
            const slots = document.querySelectorAll('.hand-slot');
            const targetSlot = slots[newCardSlotIndex];
            if (targetSlot) targetSlot.classList.add('loading');

            loadHandCards();
            setTimeout(() => {
                const updatedSlots = document.querySelectorAll('.hand-slot');
                const newSlot = updatedSlots[newCardSlotIndex];
                if (newSlot && newSlot.classList.contains('filled')) {
                    animateHandCardIn(newSlot);
                }
            }, 200);
        } else {
            alert('Failed to draw spicy card: ' + data.message);
        }
    });
}

function updateHandSlots() {
    const slots = document.querySelectorAll('.hand-slot');
    const handCountEl = document.getElementById('handCardCount');
    
    // Clear all slots
    slots.forEach(slot => {
        if (!slot) return;
        slot.classList.remove('filled', 'expanded');
        slot.classList.add('empty');
        slot.innerHTML = '<div class="empty-slot-indicator">Empty</div>';
    });
    
    // Update hand count
    const totalCards = handCards.reduce((sum, card) => sum + card.quantity, 0);
    if (handCountEl) handCountEl.textContent = totalCards;

    // CHECK FOR HAND COUNT INCREASE AND ADD ANIMATION
    if (totalCards > previousHandCount && !isInitialHandLoad) {
        const gameTimerHand = document.querySelector('.game-timer-hand');
        if (gameTimerHand) {
            gameTimerHand.classList.add('increased');
            setTimeout(() => {
                gameTimerHand.classList.remove('increased');
            }, 2000);
        }
    }
    previousHandCount = totalCards;
    isInitialHandLoad = false;

    if(gameData.gameStatus === 'active') {
        // Update app badge
        updateAppBadge(totalCards);
    } else {
        clearAppBadge();
    }
    
    // Fill slots with cards
    let slotIndex = 0;
    handCards.forEach(card => {
        for (let i = 0; i < card.quantity && slotIndex < 10; i++) {
            const slot = slots[slotIndex];
            if (!slot) continue;
            
            slot.classList.remove('empty');
            slot.classList.add('filled');
            slot.innerHTML = createHandCardHTML(card, slotIndex);
            slotIndex++;
        }
    });
}

function createHandCardHTML(card, slotIndex) {
    const cardTypeIcons = {
        'stored_challenge': 'fa-flag-checkered',
        'power': 'fa-star',
        'snap': 'fa-camera-retro',
        'spicy': 'fa-pepper-hot'
    };
    
    const icon = cardTypeIcons[card.card_type] || 'fa-square';
    
    let badges = '';
    if (card.effective_points) {
        badges += `<div class="hand-slot-card-badge points">+${card.effective_points}</div>`;
    }
    if (card.veto_subtract) {
        badges += `<div class="hand-slot-card-badge penalty">-${card.veto_subtract}</div>`;
    }
    if (card.veto_steal) {
        badges += `<div class="hand-slot-card-badge penalty"><i class="fa-solid fa-hand"></i> ${card.veto_steal}</div>`;
    }
    if (card.veto_wait) {
        badges += `<div class="hand-slot-card-badge penalty"><i class="fa-solid fa-circle-pause"></i> ${card.veto_wait}</div>`;
    }
    
    const actions = getHandCardActions(card.card_type);
    const actionsHTML = `
        <div class="hand-slot-actions">
            ${actions.map(action => `
                <button class="hand-slot-action-btn ${action.class || ''}" onclick="${action.onClick}(${card.id}); closeHandSlotActions(${slotIndex})">
                    ${action.text}
                </button>
            `).join('')}
        </div>
    `;
    
    return `
        <div class="hand-slot-card" onclick="toggleHandSlotActions(${slotIndex})">
            <div class="hand-slot-card-content">
                <div class="hand-slot-card-header">
                    <div class="hand-slot-card-type-icon ${card.card_type}">
                        <i class="fa-solid ${icon}"></i>
                    </div>
                    <div class="hand-slot-card-info">
                        <div class="hand-slot-card-name">${card.card_name}</div>
                    </div>
                </div>
                <div class="hand-slot-card-description">${card.card_description}</div>
                <div class="hand-slot-card-meta">
                    ${badges}
                </div>
            </div>
            ${card.quantity > 1 ? `<div class="hand-slot-card-quantity">${card.quantity}</div>` : ''}
        </div>
        ${actionsHTML}
    `;
}

function toggleHandSlotActions(slotIndex) {
    const slots = document.querySelectorAll('.hand-slot');
    const targetSlot = slots[slotIndex];
    
    // Close other expanded slots
    slots.forEach((slot, index) => {
        if (index !== slotIndex) {
            slot.classList.remove('expanded');
        }
    });
    
    // Toggle target slot
    targetSlot.classList.toggle('expanded');
}

function closeHandSlotActions(slotIndex) {
    const slots = document.querySelectorAll('.hand-slot');
    if (slots[slotIndex]) {
        slots[slotIndex].classList.remove('expanded');
    }
}

function getCardTypeIconClass(type) {
    const icons = {
        'challenge': 'fa-flag-checkered',
        'power': 'fa-star',
        'curse': 'fa-skull-crossbones',
        'snap': 'fa-camera-retro',
        'spicy': 'fa-pepper-hot'
    };
    return icons[type] || 'fa-square';
}

// ========================================
// VETO WAIT SYSTEM
// ========================================

function checkVetoWait() {
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=check_veto_wait'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (data.is_waiting) {
                // Update end time if it changed
                if (data.wait_until) {
                    const newEndTime = new Date(data.wait_until);
                    if (!vetoWaitEndTime || newEndTime.getTime() !== vetoWaitEndTime.getTime()) {
                        vetoWaitEndTime = newEndTime;
                        console.log('Updated veto wait end time:', vetoWaitEndTime);
                    }
                }
                
                if (!isVetoWaiting) {
                    console.log('Starting veto wait display');
                    startVetoWaitDisplay(data.wait_until);
                }
                updateVetoWaitCountdown();
            } else {
                if (isVetoWaiting) {
                    console.log('Ending veto wait display');
                    endVetoWaitDisplay();
                }
            }
        }
    });
}

function startVetoWaitDisplay(waitUntil) {
    hideDowntimeOverlay();
    console.log('Starting veto wait display');
    isVetoWaiting = true;
    
    vetoWaitEndTime = new Date(waitUntil);
    console.log('Veto wait end time:', vetoWaitEndTime);

    $('.daily-deck-container').addClass('wait');
    console.log('Added wait class to daily-deck-container');
    
    const overlay = document.getElementById('vetoWaitOverlay');
    if (overlay) {
        overlay.style.display = 'flex';
        updateVetoWaitCountdown();
        
        const countdownInterval = setInterval(() => {
            if (!isVetoWaiting) {
                console.log('Clearing countdown interval - veto wait ended');
                clearInterval(countdownInterval);
                return;
            }
            updateVetoWaitCountdown();
        }, 1000);
    }
}

function updateVetoWaitCountdown() {
    const countdown = document.getElementById('vetoCountdown');
    if (!countdown || !vetoWaitEndTime) return true;
    
    const now = new Date();
    const diff = vetoWaitEndTime - now;
    
    if (diff <= 0) {
        endVetoWaitDisplay();
        return false;
    }
    
    const minutes = Math.floor(diff / (1000 * 60));
    const seconds = Math.floor((diff % (1000 * 60)) / 1000);
    
    countdown.textContent = `${minutes}:${seconds.toString().padStart(2, '0')}`;
    return true;
}

function endVetoWaitDisplay() {
    console.log('Ending veto wait display - isVetoWaiting was:', isVetoWaiting);
    console.trace(); // This will show the call stack
    
    isVetoWaiting = false;
    vetoWaitEndTime = null;

    $('.daily-deck-container').removeClass('wait');
    console.log('Removed wait class from daily-deck-container');
    
    const overlay = document.getElementById('vetoWaitOverlay');
    if (overlay) {
        overlay.style.display = 'none';
    }
}

function checkDeckEmpty() {
    if (!gameData || gameData.gameStatus !== 'active') return;
    
    // Don't show deck empty during downtime
    if (checkDowntime()) return;
    
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=check_deck_empty'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.is_empty) {
            showDeckEmptyOverlay();
        } else {
            hideDeckEmptyOverlay();
        }
    });
}

function showDeckEmptyOverlay() {
    // Don't show if in veto wait (wait has priority)
    if (isVetoWaiting) return;
    
    const container = document.querySelector('.daily-deck-container');
    if (!container) return;
    
    container.classList.add('deck-empty');
    
    let overlay = document.getElementById('deckEmptyOverlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'deckEmptyOverlay';
        overlay.className = 'deck-empty-overlay';
        overlay.innerHTML = `
            <div class="deck-empty-message">Daily Deck Complete</div>
            <div class="deck-empty-subtitle">Check back tomorrow for new cards</div>
        `;
        container.appendChild(overlay);
    }
    overlay.style.display = 'flex';
}

function hideDeckEmptyOverlay() {
    const container = document.querySelector('.daily-deck-container');
    const overlay = document.getElementById('deckEmptyOverlay');
    
    if (container) container.classList.remove('deck-empty');
    if (overlay) overlay.style.display = 'none';
}

function checkCurseBlock() {
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=check_blocking_curses'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.is_blocked) {
            showCurseBlockOverlay(data.curse_name, data.card_type);
        } else {
            hideCurseBlockOverlay();
        }
    });
}

function showCurseBlockOverlay(curseName, cardType) {
    const overlay = document.getElementById('curseBlockOverlay');
    const message = document.getElementById('curseBlockMessage');
    const requirement = document.getElementById('curseBlockRequirement');
    
    if (overlay && message && requirement) {
        message.innerHTML = '<i class="fa-solid fa-skull-crossbones"></i> ' + curseName;
        requirement.textContent = `Complete a ${cardType} card to clear this curse.`;
        overlay.style.display = 'flex';
        
        const container = document.querySelector('.daily-deck-container');
        if (container) container.classList.add('wait');
    }
}

function hideCurseBlockOverlay() {
    const overlay = document.getElementById('curseBlockOverlay');
    if (overlay) {
        overlay.style.display = 'none';
    }
    
    // Only remove wait class if not in veto wait
    if (!isVetoWaiting) {
        const container = document.querySelector('.daily-deck-container');
        if (container) container.classList.remove('wait');
    }
}

// ========================================
// STATUS EFFECTS SYSTEM
// ========================================

$('#opponentCurseTimer').on('click', function() {
    showStatusEffectSlideout('curse', true);
});
$('#playerCurseTimer').on('click', function() {
    showStatusEffectSlideout('curse', false)
});

function updateStatusEffects() {
    // Only update status effects if game is active
    if (!gameData || gameData.gameStatus !== 'active') {
        return;
    }
    
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=get_status_effects'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            displayStatusEffects('playerStatusEffects', data.player_effects);
            displayStatusEffects('opponentStatusEffects', data.opponent_effects);
        }
    });
}

function showStatusEffectSlideout(effectType, isOpponent = false) {
    const slideout = document.getElementById('statusEffectSlideout');
    const title = document.getElementById('slideoutTitle');
    const content = document.getElementById('slideoutContent');
    
    if (!slideout || !title || !content) return;
    
    // Clear any existing timeout
    if (slideoutTimeout) {
        clearTimeout(slideoutTimeout);
    }

    if(waitCountdownInterval) {
        clearInterval(waitCountdownInterval);
        waitCountdownInterval = null;
    }
    
    // Check if already showing and needs to hide first
    const isCurrentlyActive = slideout.classList.contains('active');
    
    if (isCurrentlyActive) {
        // Hide first, then show new content after transition
        slideout.classList.remove('active');
        
        setTimeout(() => {
            setupSlideoutContent(slideout, title, content, effectType, isOpponent);
        }, 300); // Match CSS transition duration
    } else {
        // Show immediately
        setupSlideoutContent(slideout, title, content, effectType, isOpponent);
    }
}

function setupSlideoutContent(slideout, title, content, effectType, isOpponent) {
    // Set up slideout based on type
    slideout.className = `status-effect-slideout ${effectType}-type`;
    
    if (effectType === 'wait') {
        showWaitSlideout(title, content, isOpponent);
    } else {
        showEffectsSlideout(effectType, title, content, isOpponent);
    }
    
    // Show slideout
    setTimeout(() => {
        slideout.classList.add('active');
    }, 50);
    
    // Auto-hide after 5 seconds
    slideoutTimeout = setTimeout(() => {
        hideStatusEffectSlideout();
    }, 5000);
}

function showWaitSlideout(title, content, isOpponent) {
    title.innerHTML = '<i class="fa-solid fa-circle-pause"></i> Wait Penalty';
    
    // Get wait time from the correct system
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=get_veto_wait_status${isOpponent ? '&target=opponent' : ''}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.wait_until) {
            const waitUntil = new Date(data.wait_until);
            
            function updateCountdown() {
                const now = new Date();
                const diff = waitUntil - now;
                
                if (diff <= 0) {
                    content.innerHTML = `<div class="slideout-countdown">0:00</div>`;
                    if (waitCountdownInterval) {
                        clearInterval(waitCountdownInterval);
                        waitCountdownInterval = null;
                    }
                    return;
                }
                
                const minutes = Math.floor(diff / (1000 * 60));
                const seconds = Math.floor((diff % (1000 * 60)) / 1000);
                const waitTime = `${minutes}:${seconds.toString().padStart(2, '0')}`;
                content.innerHTML = `<div class="slideout-countdown">${waitTime}</div>`;
            }
            
            // Initial update
            updateCountdown();
            
            // Start interval
            waitCountdownInterval = setInterval(updateCountdown, 1000);
        } else {
            content.innerHTML = `<div class="slideout-countdown">0:00</div>`;
        }
    })
    .catch(() => {
        content.innerHTML = `<div class="slideout-countdown">0:00</div>`;
    });
}

function showEffectsSlideout(effectType, title, content, isOpponent) {
    const iconClass = effectType === 'curse' ? 'fa-skull-crossbones' : 'fa-star';
    const typeLabel = effectType === 'curse' ? 'Curses' : 'Powers';
    
    title.innerHTML = `<i class="fa-solid ${iconClass}"></i> Active ${typeLabel}`;
    content.innerHTML = '<div>Loading...</div>';
    
    // Fetch specific effect type details
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=get_active_effects_details${isOpponent ? '&target=opponent' : ''}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const effects = effectType === 'curse' ? data.curse_effects : data.power_effects;
            
            if (effects.length === 0) {
                content.innerHTML = `<div>No active ${effectType}s</div>`;
            } else {
                content.innerHTML = effects.map(effect => `
                    <div class="slideout-effect">
                        <div class="slideout-effect-name">${effect.card_name}</div>
                        <div>${effect.card_description}</div>
                        ${effect.expires_at ? `<div style="margin-top: 4px; opacity: 0.8;">Expires: ${new Date(effect.expires_at + 'Z').toLocaleString('en-US', {
  timeZone: 'America/Indianapolis',
  hour: 'numeric',
  minute: 'numeric',
  second: 'numeric'
})}</div>` : ''}
                    </div>
                `).join('');
            }
        }
    });
}

function hideStatusEffectSlideout() {
    const slideout = document.getElementById('statusEffectSlideout');
    if (slideout) {
        slideout.classList.remove('active');
    }
    if (slideoutTimeout) {
        clearTimeout(slideoutTimeout);
        slideoutTimeout = null;
    }
    // Clear wait countdown interval
    if (waitCountdownInterval) {
        clearInterval(waitCountdownInterval);
        waitCountdownInterval = null;
    }
}

function displayStatusEffects(containerId, effects) {
    const container = document.getElementById(containerId);
    if (!container) return;
    
    container.innerHTML = '';
    
    // Determine if this is opponent's container
    const isOpponent = containerId === 'opponentStatusEffects';
    
    effects.forEach(effect => {
        const span = document.createElement('span');
        span.className = 'status-effect';
        span.style.backgroundColor = effect.color;
        span.style.cursor = 'pointer';
        span.addEventListener('click', function(e) {
            e.stopPropagation();
            // Only show slideout if score bug is not expanded
            const scoreBug = document.getElementById('scoreBug');
            if (!scoreBug.classList.contains('expanded')) {
                showStatusEffectSlideout(effect.type, isOpponent);
            }
        });
        
        if (effect.type === 'curse') {
            span.innerHTML = '<i class="fa-solid fa-skull-crossbones"></i>';
        } else if (effect.type === 'power') {
            span.innerHTML = '<i class="fa-solid fa-star"></i>';
        } else if (effect.type === 'wait') {
            span.innerHTML = '<i class="fa-solid fa-circle-pause"></i>';
        } else {
            span.textContent = effect.icon;
        }
        
        span.title = effect.type;
        container.appendChild(span);
    });
}

// ========================================
// SCORE BUG SYSTEM
// ========================================

function setupScoreBugHandlers() {
    const scoreBug = document.getElementById('scoreBug');
    
    if (scoreBug) {
        // Prevent input clicks from toggling
        const input = document.getElementById('scoreAdjustInput');
        if (input) {
            input.addEventListener('click', function(e) {
                e.stopPropagation();
            });
            input.addEventListener('touchstart', function(e) {
                e.stopPropagation();
            });
            input.addEventListener('touchend', function(e) {
                e.stopPropagation();
            });
        }

        // Add swipe up gesture
        let touchStartY = 0;
        scoreBug.addEventListener('touchstart', function(e) {
            // Don't handle if touching input
            if (e.target.id === 'scoreAdjustInput' || e.target.closest('#scoreAdjustInput')) return;
            touchStartY = e.touches[0].clientY;
        });

        scoreBug.addEventListener('touchend', function(e) {
            // Don't handle if touching input
            if (e.target.id === 'scoreAdjustInput' || e.target.closest('#scoreAdjustInput')) return;
            
            const touchEndY = e.changedTouches[0].clientY;
            const swipeDistance = touchStartY - touchEndY;
            
            if (swipeDistance > 50) { // Swipe up
                toggleScoreBugExpanded();
            }
        });
    }
    
    // Close when clicking outside (but not on input)
    document.addEventListener('click', function(e) {
        if (!e.target.closest('#scoreBug') && 
            !e.target.closest('#scoreAdjustInput') &&
            scoreBug && 
            scoreBug.classList.contains('expanded')) {
            toggleScoreBugExpanded();
        }
    });
}

function setScorebugWidth() {
    const $scoreBug = $('.score-bug');
    $scoreBug.width('');
    setTimeout(() => {
        $scoreBug.width($scoreBug.width());
    }, 100);
}

function toggleScoreBugExpanded() {
    const scoreBug = document.getElementById('scoreBug');
    const expandedContent = document.querySelector('.score-bug-expanded-content');
    
    if (!scoreBug || !expandedContent) return;
    
    const isExpanded = scoreBug.classList.contains('expanded');
    
    if (!isExpanded) {
        scoreBug.classList.add('expanded');
        
        // Calculate and set height
        const contentHeight = expandedContent.scrollHeight;
        scoreBug.style.height = `${120 + contentHeight}px`;
        
        loadAwardsInfo();
        setOverlayActive(true);
        scoreBugExpanded = true;
    } else {
        scoreBug.classList.remove('expanded');
        scoreBug.style.height = '';
        setOverlayActive(false);
        scoreBugExpanded = false;
    }
}

function loadAwardsInfo() {
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=get_awards_info'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            updateAwardsDisplay(data);
        }
    });
}

function updateAwardsDisplay(data) {
    // Player snap
    const playerSnapEl = document.getElementById('playerSnapProgress');
    const playerSnapBadge = document.getElementById('playerSnapBadge');
    if (playerSnapEl && playerSnapBadge) {
        playerSnapEl.textContent = data.player_snap_next ? 
            `${data.player_snap_next.cards_needed} to go` : 'MAX';
        playerSnapBadge.textContent = data.player_snap_next ? 
            `+${data.player_snap_next.points_reward}` : '+0';
    }
    
    // Opponent snap
    const opponentSnapEl = document.getElementById('opponentSnapProgress');
    const opponentSnapBadge = document.getElementById('opponentSnapBadge');
    if (opponentSnapEl && opponentSnapBadge) {
        opponentSnapEl.textContent = data.opponent_snap_next ? 
            `${data.opponent_snap_next.cards_needed} to go` : 'MAX';
        opponentSnapBadge.textContent = data.opponent_snap_next ? 
            `+${data.opponent_snap_next.points_reward}` : '+0';
    }
    
    // Player spicy
    const playerSpicyEl = document.getElementById('playerSpicyProgress');
    const playerSpicyBadge = document.getElementById('playerSpicyBadge');
    if (playerSpicyEl && playerSpicyBadge) {
        playerSpicyEl.textContent = data.player_spicy_next ? 
            `${data.player_spicy_next.cards_needed} to go` : 'MAX';
        playerSpicyBadge.textContent = data.player_spicy_next ? 
            `+${data.player_spicy_next.points_reward}` : '+0';
    }
    
    // Opponent spicy
    const opponentSpicyEl = document.getElementById('opponentSpicyProgress');
    const opponentSpicyBadge = document.getElementById('opponentSpicyBadge');
    if (opponentSpicyEl && opponentSpicyBadge) {
        opponentSpicyEl.textContent = data.opponent_spicy_next ? 
            `${data.opponent_spicy_next.cards_needed} to go` : 'MAX';
        opponentSpicyBadge.textContent = data.opponent_spicy_next ? 
            `+${data.opponent_spicy_next.points_reward}` : '+0';
    }
    
    // Challenge master - keep as before
    const playerChallengeEl = document.getElementById('playerChallengeCount');
    if (playerChallengeEl && data.player_stats) {
        playerChallengeEl.textContent = data.player_stats.challenges_completed;
    }
    
    const opponentChallengeEl = document.getElementById('opponentChallengeCount');
    if (opponentChallengeEl && data.opponent_stats) {
        opponentChallengeEl.textContent = data.opponent_stats.challenges_completed;
    }
}

// Score adjustment functions
function adjustScore(playerId, points) {
    updateScore(playerId, points);
}

function stealPoints(fromPlayerId, toPlayerId, points) {
    updateScore(fromPlayerId, -points);
    setTimeout(() => updateScore(toPlayerId, points), 1000);
}

// ========================================
// GAME MANAGEMENT
// ========================================

function updateScore(playerId, points) {
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=update_score&player_id=${playerId}&points=${points}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Get fresh data immediately after score update
            setTimeout(() => refreshGameData(), 500);
        } else {
            alert('Failed to update score. Please try again.');
        }
    })
    .catch(error => {
        console.error('Error updating score:', error);
        alert('Failed to update score. Please try again.');
    });
}

function refreshGameData() {
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=get_game_data'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            try {
                updateScoreDisplay(data.players);
            } catch (error) {
                console.error('Error in updateScoreDisplay:', error);
            }
            updateGameTimer(data.gametime);
            
            if (data.game_expired && gameData.gameStatus === 'active') {
                endGame();
            }
        }
    })
    .catch(error => {
        console.error('Error refreshing game data:', error);
    });
}

function updateScoreDisplay(players) {
    
    players.forEach(player => {
        const isCurrentPlayer = player.id === gameData.currentPlayerId;
        
        // Find the score element using a more specific selector
        let scoreElement;
        if (isCurrentPlayer) {
            scoreElement = document.querySelector('.score-bug .player-score-section.current .player-score');
        } else {
            scoreElement = document.querySelector('.score-bug .player-score-section.opponent .player-score');
        }
        
        
        if (scoreElement) {
            animateScoreChange(scoreElement, player.score);
        }
    });
}

function animateScoreChange(element, newScore) {
    const currentScore = parseInt(element.textContent) || 0;
    if (currentScore === newScore) return;
    
    const scoreDiff = newScore - currentScore;
    const scoreBug = element.closest('.score-bug');
    const isCurrentPlayer = element.closest('.player-score-section').classList.contains('current');
    
    // Create score flyout
    const flyout = document.createElement('div');
    flyout.className = 'score-flyout';
    flyout.textContent = (scoreDiff > 0 ? '+' : '') + scoreDiff;
    flyout.style.cssText = `
        ${isCurrentPlayer ? 'right: 5%;' : 'left: 5%;'}
    `;
    scoreBug.appendChild(flyout);
    
    // Animate flyout
    setTimeout(() => {
        flyout.remove();
    }, 2200);
    
    // Animate score element with 3D rotation
    element.classList.add('animate');
    
    playSoundIfEnabled('/score-change.m4r');
    
    // Update score text at 540ms (around 270deg)
    setTimeout(() => {
        element.textContent = newScore;
    }, 540);
    
    // Remove animate class
    setTimeout(() => {
        element.classList.remove('animate');
    }, 900);
}

function updateGameTimer(timeText) {
    const gameTimer = document.querySelector('.game-timer');
    if (gameTimer) {
        gameTimer.textContent = timeText;
        
        if (timeText === 'Game Ended') {
            location.reload();
        }
    }
}

// ========================================
// SOUND MANAGEMENT
// ========================================

function setSoundEnabled(enabled) {
    localStorage.setItem('couples_quest_sound_enabled', enabled ? 'true' : 'false');
    updateSoundIcon();
}

function isSoundEnabled() {
    return localStorage.getItem('couples_quest_sound_enabled') !== 'false';
}

function updateSoundIcon() {
    const icon = document.getElementById('soundToggleIcon');
    if (icon) {
        icon.className = isSoundEnabled() ? 'fa-solid fa-volume-high' : 'fa-solid fa-volume-xmark';
    }
}

function toggleSound() {
    const enabled = !isSoundEnabled();
    setSoundEnabled(enabled);
    playSoundIfEnabled('/tritone.m4r'); // Play feedback sound if enabled
}

function playSoundIfEnabled(soundFile) {
    if (isSoundEnabled()) {
        actionSound.src = soundFile;
        actionSound.play().catch(() => {});
    }
}

// ========================================
// UTILITY FUNCTIONS
// ========================================

function setOverlayActive(active) {
    if (active) {
        document.body.classList.add('overlay-active');
    } else {
        document.body.classList.remove('overlay-active');
    }
}

function showInAppNotification(title, body) {
    const $notification = $('.iAN');
    const $title = $notification.find('.iAN-title');
    const $body = $notification.find('.iAN-body');
    
    if ($notification.length === 0) return;
    
    $notification.removeClass('show');
    $title.text(title);
    $body.text(body);
    
    setTimeout(() => {
        $notification.addClass('show');
    }, 10);
    
    playSoundIfEnabled('/tritone.m4r');
    
    setTimeout(() => {
        $notification.removeClass('show');
    }, 5000);
    
    setTimeout(() => {
        $title.empty();
        $body.empty();
    }, 5500);
}

// ========================================
// MODAL MANAGEMENT
// ========================================

function openNotifyModal() {
    const modal = document.getElementById('notifyModal');
    if (modal) {
        modal.classList.add('active');
        setOverlayActive(true);
        setTimeout(checkNotificationStatusForModal, 100);
    }
}

function openTimerModal() {
    const modal = document.getElementById('timerModal');
    if (modal) {
        modal.classList.add('active');
        setOverlayActive(true);
    }
}

function openHistoryModal() {
    loadHistory();
    const modal = document.getElementById('historyModal');
    if (modal) {
        modal.classList.add('active');
        setOverlayActive(true);
    }
}

function openEndGameModal() {
    const modal = document.getElementById('endGameModal');
    if (modal) {
        modal.classList.add('active');
        setOverlayActive(true);
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('active');
        setOverlayActive(false);
    }
}

function setupModalHandlers() {
    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                this.classList.remove('active');
                setOverlayActive(false);
            }
        });
    });
}

// ========================================
// TIMER MANAGEMENT
// ========================================

function createTimer() {
    const description = document.getElementById('timerDescription');
    const minutes = document.getElementById('timerDuration');
    
    if (!description || !minutes) {
        alert('Timer form elements not found');
        return;
    }
    
    if (!description.value.trim()) {
        alert('Please enter a description');
        return;
    }
    
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=create_timer&description=${encodeURIComponent(description.value)}&minutes=${minutes.value}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            description.value = '';
            refreshGameData();
            closeModal('timerModal');
        } else {
            alert('Failed to create timer. Please try again.');
        }
    })
    .catch(error => {
        console.error('Error creating timer:', error);
        alert('Failed to create timer. Please try again.');
    });
}

function loadHistory() {
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=get_game_data'
    })
    .then(response => response.json())
    .then(data => {
        const historyContent = document.getElementById('historyContent');
        if (!historyContent) return;
        
        historyContent.innerHTML = '';
        
        if (!data.history || data.history.length === 0) {
            historyContent.innerHTML = '<p style="text-align: center; color: #666;">No score changes in the last 24 hours</p>';
            return;
        }
        
        data.history.forEach(item => {
            const div = document.createElement('div');
            div.className = 'history-item';
            
            const time = new Date(item.timestamp);
            const options = {
                month: 'long',
                day: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
                hour12: true
            };
            const formattedDate = new Intl.DateTimeFormat('en-US', options).format(time);
            const change = Math.abs(item.points_changed);
            const action = item.points_changed < 0 ? 'subtracted' : 'added';
            const preposition = item.points_changed < 0 ? 'from' : 'to';
            const pointWord = change === 1 ? 'point' : 'points';
            
            div.innerHTML = `
                <div class="history-time">${formattedDate}</div>
                <div class="history-change">
                    ${item.modified_by_name} ${action} ${change} ${pointWord} ${preposition} ${item.player_name}'s score
                </div>
            `;
            
            historyContent.appendChild(div);
        });
    })
    .catch(error => {
        console.error('Error loading history:', error);
        const historyContent = document.getElementById('historyContent');
        if (historyContent) {
            historyContent.innerHTML = '<p style="text-align: center; color: #666;">Failed to load history</p>';
        }
    });
}

// ========================================
// GAME ACTIONS
// ========================================

function sendBump() {
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=send_bump'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showInAppNotification('Bump Sent!', data.message);
            playSoundIfEnabled('/bumped.m4r');
        } else {
            showInAppNotification('Bump Failed', data.message);
        }
    })
    .catch(error => {
        console.error('Error sending bump:', error);
        showInAppNotification('Bump Failed', 'Network error');
    });
}

function endGame() {
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=end_game'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            clearAppBadge();
            location.reload();
        } else {
            alert('Failed to end game: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error ending game:', error);
        alert('Failed to end game. Please try again.');
    });
}

function readyForNewGame() {
    const button = document.getElementById('newGameBtn');
    button.disabled = true;
    button.textContent = 'Getting Ready...';
    
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=ready_for_new_game'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (data.redirect) {
                window.location.reload();
            } else {
                button.textContent = 'Ready ✓';
                button.style.background = '#51cf66';
                startNewGamePolling();
            }
        } else {
            button.disabled = false;
            button.textContent = 'Start New Game';
            alert('Failed to ready for new game: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error readying for new game:', error);
        button.disabled = false;
        button.textContent = 'Start New Game';
        alert('Failed to ready for new game.');
    });
}

function startNewGamePolling() {
    const pollInterval = setInterval(() => {
        fetch('game.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=get_new_game_status'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.game_reset) {
                clearInterval(pollInterval);
                window.location.reload();
            }
        })
        .catch(error => {
            console.error('Error polling new game status:', error);
        });
    }, 5000);
}

// ========================================
// DURATION AND MODE SETUP
// ========================================

function setupModeButtons() {
    document.querySelectorAll('.mode-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const modeId = this.dataset.mode;
            
            fetch('game.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=set_travel_mode&mode_id=' + modeId
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Failed to set travel mode. Please try again.');
                }
            })
            .catch(error => {
                console.error('Error setting travel mode:', error);
                alert('Failed to set travel mode. Please try again.');
            });
        });
    });
}

function setGameDates() {
    const startDate = document.getElementById('startDate').value;
    const endDate = document.getElementById('endDate').value;
    
    if (!startDate || !endDate) {
        alert('Please select both start and end dates');
        return;
    }
    
    const start = new Date(startDate + 'T00:00:00');
    const end = new Date(endDate + 'T00:00:00');
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    start.setHours(0, 0, 0, 0);

    console.log('today is set to ' + today + ' & start is set to ' + start);

    if (start < today) {
        alert('Start date cannot be in the past');
        return;
    }
    
    const daysDiff = Math.ceil((end - start) / (1000 * 60 * 60 * 24));
    
    if (daysDiff < 1) {
        alert('End date must be at least 1 day after start date');
        return;
    }
    
    if (daysDiff > 14) {
        alert('Maximum adventure length is 14 days');
        return;
    }
    
    document.getElementById('setDatesBtn').disabled = true;
    document.getElementById('setDatesBtn').textContent = 'Setting dates...';
    
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=set_game_dates&start_date=${startDate}&end_date=${endDate}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Failed to set dates: ' + (data.message || 'Unknown error'));
            document.getElementById('setDatesBtn').disabled = false;
            document.getElementById('setDatesBtn').textContent = 'Start Adventure';
        }
    });
}

// ========================================
// WAITING SCREEN POLLING
// ========================================

function setupWaitingScreenPolling() {
    // Waiting for opponent
    if (document.querySelector('.waiting-screen.no-opponent')) {
        console.log('Starting opponent check polling...');
        
        function checkForOpponent() {
            fetch('game.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=check_game_status'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && (data.status !== 'waiting' || data.player_count >= 2)) {
                    window.location.reload();
                }
            })
            .catch(error => {
                console.error('Error checking game status:', error);
            });
        }
        
        const statusInterval = setInterval(checkForOpponent, 5000);
        window.addEventListener('beforeunload', () => clearInterval(statusInterval));
    }
    
    // Waiting for mode selection
    if (document.querySelector('.waiting-screen.mode-selection')) {
        console.log('Starting mode selection polling...');
        
        function checkForModeSelection() {
            fetch('game.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=check_game_status'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.travel_mode_id) {
                    window.location.reload();
                }
            })
            .catch(error => {
                console.error('Error checking game status:', error);
            });
        }
        
        const modeInterval = setInterval(checkForModeSelection, 5000);
        window.addEventListener('beforeunload', () => clearInterval(modeInterval));
    }
    
    // Waiting for duration
    if (document.querySelector('.waiting-screen.duration')) {
        console.log('Starting game status polling...');
        
        function checkForStatusChange() {
            fetch('game.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=check_game_status'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.status !== 'waiting' || data.success && data.start_date) {
                    window.location.reload();
                }
            })
            .catch(error => {
                console.error('Error checking game status:', error);
            });
        }
        
        const statusInterval = setInterval(checkForStatusChange, 5000);
        window.addEventListener('beforeunload', () => clearInterval(statusInterval));
    }
}

// ========================================
// FIREBASE AND NOTIFICATIONS
// ========================================

function initializeFirebase() {
    console.log('Initializing Firebase...');
    
    if (typeof firebase === 'undefined') {
        console.log('Firebase not loaded, skipping initialization');
        return;
    }

    const firebaseConfig = {
        apiKey: "AIzaSyB8H4ClwOR00oxcBENYgi8yiVVMHQAUCSc",
        authDomain: "couples-quest-5b424.firebaseapp.com",
        projectId: "couples-quest-5b424",
        storageBucket: "couples-quest-5b424.firebasestorage.app",
        messagingSenderId: window.fcmSenderId || "551122707531",
        appId: "1:551122707531:web:30309743eea2fe410b19ce"
    };

    try {
        firebase.initializeApp(firebaseConfig);
        firebaseMessaging = firebase.messaging();

        firebaseMessaging.onMessage((payload) => {
            console.log('Firebase message received in foreground:', payload);
            
            let title = payload.notification?.title || payload.data?.title || 'The Couples Quest';
            let body = payload.notification?.body || payload.data?.body || 'New notification';
            
            showInAppNotification(title, body);
        });

        console.log('Firebase initialized successfully');
    } catch (error) {
        console.error('Firebase initialization failed:', error);
    }
}

function setupFirebaseMessaging() {
    if (!firebaseMessaging) return;
    
    const vapidKey = 'BAhDDY44EUfm9YKOElboy-2fb_6lzVhW4_TLMr4Ctiw6oA_ROcKZ09i5pKMQx3s7SoWgjuPbW-eGI7gFst6qjag';
    
    firebaseMessaging.getToken({ vapidKey }).then((currentToken) => {
        if (currentToken) {
            console.log('FCM Token received:', currentToken);
            updateTokenOnServer(currentToken);
            localStorage.setItem('fcm_token', currentToken);
        }
    }).catch((err) => {
        console.log('Error getting FCM token:', err);
    });

    firebaseMessaging.onMessage((payload) => {
        console.log('Message received in foreground:', payload);
        
        let title = payload.notification?.title || payload.data?.title || 'The Couples Quest';
        let body = payload.notification?.body || payload.data?.body || 'New notification';
        
        showInAppNotification(title, body);
    });
}

function updateTokenOnServer(token) {
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=update_fcm_token&fcm_token=' + encodeURIComponent(token)
    })
    .then(response => response.json())
    .then(data => {
        console.log('Token update result:', data);
    })
    .catch(error => {
        console.error('Error updating token:', error);
        setTimeout(() => updateTokenOnServer(token), 5000);
    });
}

function checkNotificationStatus() {
    const button = document.getElementById('enableNotificationsBtn');
    const status = document.getElementById('notificationStatus');
    
    if (!button || !status) return;
    
    if (!('Notification' in window)) {
        button.textContent = 'Not Supported';
        button.disabled = true;
        status.innerHTML = '<span style="color: #ff6b6b;">❌ Notifications not supported</span>';
        return;
    }
    
    if (Notification.permission === 'granted') {
        button.textContent = 'Enabled ✓';
        button.style.background = '#51cf66';
        status.innerHTML = '<span style="color: #51cf66;">✅ Notifications are enabled</span>';
        
        if (firebaseMessaging) {
            setupFirebaseMessaging();
        }
    } else if (Notification.permission === 'denied') {
        button.textContent = 'Blocked';
        status.innerHTML = '<span style="color: #ff6b6b;">❌ Notifications blocked in browser settings</span>';
    } else {
        button.textContent = 'Enable Notifications';
        status.innerHTML = '<span style="color: #868e96;">Click to enable notifications</span>';
    }
}

function checkNotificationStatusForModal() {
    const status = document.getElementById('notificationModalStatus');
    const statusText = document.getElementById('notificationModalStatusText');
    const button = document.getElementById('enableNotificationsModalBtn');
    const testButton = document.getElementById('testNotificationBtn');
    
    if (!status || !statusText || !button) return;
    
    if (!('Notification' in window)) {
        statusText.textContent = '❌ Notifications not supported in this browser';
        status.className = 'notification-status blocked';
        button.textContent = 'Not Supported';
        button.disabled = true;
        return;
    }
    
    if (Notification.permission === 'granted') {
        statusText.textContent = '✅ Notifications are enabled!';
        status.className = 'notification-status enabled';
        button.textContent = 'Enabled ✓';
        button.style.background = '#51cf66';
        button.disabled = true;
        
        if (testButton) {
            testButton.style.display = 'block';
        }
        
        if (firebaseMessaging) {
            setupFirebaseMessaging();
        }
    } else if (Notification.permission === 'denied') {
        statusText.textContent = '❌ Notifications are blocked. Please enable in browser settings and refresh the page.';
        status.className = 'notification-status blocked';
        button.textContent = 'Blocked';
        button.disabled = false;
    } else {
        statusText.textContent = 'Click below to enable notifications for this game.';
        status.className = 'notification-status disabled';
        button.textContent = 'Enable Notifications';
        button.disabled = false;
    }
}

function testNotification() {
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=test_notification'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Test notification sent! Check your device.');
        } else {
            alert('Failed to send test notification: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error sending test notification:', error);
        alert('Failed to send test notification.');
    });
}

// ========================================
// APP BADGE FUNCTIONALITY
// ========================================

let badgeSupported = false;

function checkBadgeSupport() {
    badgeSupported = 'setAppBadge' in navigator;
    console.log('Badge support:', badgeSupported);
}

function updateAppBadge(count) {
    if (!badgeSupported) return;
    
    try {
        if (count > 0) {
            navigator.setAppBadge(count);
        } else {
            navigator.clearAppBadge();
        }
    } catch (error) {
        console.log('Badge update failed:', error);
    }
}

function clearAppBadge() {
    if (badgeSupported) {
        try {
            navigator.clearAppBadge();
        } catch (error) {
            console.log('Badge clear failed:', error);
        }
    }
}

function enableNotifications() {
    const button = document.getElementById('enableNotificationsBtn');
    const status = document.getElementById('notificationStatus');
    
    if (!button || !status) return;
    
    button.disabled = true;
    button.textContent = 'Requesting...';
    status.innerHTML = '';
    
    if (!('Notification' in window)) {
        status.innerHTML = '<span style="color: #ff6b6b;">❌ Notifications not supported in this browser</span>';
        button.textContent = 'Not Supported';
        return;
    }
    
    Notification.requestPermission().then((permission) => {
        if (permission === 'granted') {
            status.innerHTML = '<span style="color: #51cf66;">✅ Notifications enabled!</span>';
            button.textContent = 'Enabled ✓';
            button.style.background = '#51cf66';
            
            if (firebaseMessaging) {
                setupFirebaseMessaging();
            }
        } else if (permission === 'denied') {
            status.innerHTML = '<span style="color: #ff6b6b;">❌ Notifications blocked. Please enable in browser settings.</span>';
            button.textContent = 'Blocked';
            button.disabled = false;
        } else {
            status.innerHTML = '<span style="color: #ffd43b;">⚠️ Permission dismissed. Click to try again.</span>';
            button.textContent = 'Enable Notifications';
            button.disabled = false;
        }
    }).catch((error) => {
        console.error('Error requesting permission:', error);
        status.innerHTML = '<span style="color: #ff6b6b;">❌ Error requesting permission</span>';
        button.textContent = 'Error';
        button.disabled = false;
    });
}

function enableNotificationsFromModal() {
    const button = document.getElementById('enableNotificationsModalBtn');
    const status = document.getElementById('notificationModalStatus');
    const statusText = document.getElementById('notificationModalStatusText');
    const testButton = document.getElementById('testNotificationBtn');
    
    if (!button || !status || !statusText) return;
    
    button.disabled = true;
    button.textContent = 'Requesting...';
    statusText.textContent = 'Requesting permission...';
    status.className = 'notification-status disabled';
    
    localStorage.removeItem('fcm_token');
    if (firebaseMessaging) {
        firebaseMessaging.deleteToken().catch(() => {});
    }
    
    if (!('Notification' in window)) {
        statusText.textContent = '❌ Notifications not supported in this browser';
        status.className = 'notification-status blocked';
        button.textContent = 'Not Supported';
        return;
    }
    
    Notification.requestPermission().then((permission) => {
        if (permission === 'granted') {
            statusText.textContent = '✅ Notifications are enabled!';
            status.className = 'notification-status enabled';
            button.textContent = 'Enabled ✓';
            button.style.background = '#51cf66';
            button.disabled = true;
            
            if (testButton) {
                testButton.style.display = 'block';
            }
            
            if (firebaseMessaging) {
                setupFirebaseMessaging();
            }
        } else if (permission === 'denied') {
            statusText.textContent = '❌ Notifications blocked. Please enable in browser settings and refresh the page.';
            status.className = 'notification-status blocked';
            button.textContent = 'Blocked';
            button.disabled = false;
        } else {
            statusText.textContent = '⚠️ Permission dismissed. Click to try again.';
            status.className = 'notification-status disabled';
            button.textContent = 'Enable Notifications';
            button.disabled = false;
        }
    }).catch((error) => {
        console.error('Error requesting permission:', error);
        statusText.textContent = '❌ Error requesting permission';
        status.className = 'notification-status blocked';
        button.textContent = 'Error';
        button.disabled = false;
    });
}

// ========================================
// GLOBAL EVENT HANDLERS AND CLEANUP
// ========================================

// Check notification status on page load
setTimeout(checkNotificationStatus, 500);

// Service worker heartbeat
if ('serviceWorker' in navigator) {
    setInterval(() => {
        navigator.serviceWorker.ready.then(registration => {
            if (registration.active) {
                registration.active.postMessage({type: 'HEARTBEAT'});
            }
        });
    }, 30000);
}

// Cleanup on page unload
window.addEventListener('beforeunload', () => {
    // Clear any active intervals
    if (window.gameIntervals) {
        window.gameIntervals.forEach(interval => clearInterval(interval));
    }
});

// ========================================
// DICE SYSTEM
// ========================================

let currentDiceCount = 2;
let isDiceRolling = false;
let curseDiceCallback = null;

function openDicePopover(callback = null) {
    // Collapse score bug if expanded
    const scoreBug = document.getElementById('scoreBug');
    if (scoreBug && scoreBug.classList.contains('expanded')) {
        toggleScoreBugExpanded();
    }

    const popover = document.getElementById('dicePopover');
    if (popover) {
        if (popover.classList.contains('active')) {
            closeDicePopover();
            return;
        }
        curseDiceCallback = callback;
        if (callback) {
            // Auto-roll for curse
            rollDiceChoice(2);
        } else {
            // Show choice for manual roll
            showDiceChoiceButtons();
        }
        popover.classList.add('active');
        setTimeout(() => {
            document.addEventListener('click', closeDicePopoverOnClickOutside);
        }, 100);
    }
}

function closeDicePopover() {
    const popover = document.getElementById('dicePopover');
    if (popover) {
        popover.classList.remove('active');
        document.removeEventListener('click', closeDicePopoverOnClickOutside);
        curseDiceCallback = null;
    }
}

function closeDicePopoverOnClickOutside(event) {
    const popover = document.getElementById('dicePopover');
    if (popover && !popover.contains(event.target)) {
        closeDicePopover();
    }
}

function showDiceChoiceButtons() {
    const container = document.getElementById('dicePopoverContainer');
    container.innerHTML = `
        <div class="dice-choice-buttons">
            <button class="dice-choice-btn" onclick="event.stopPropagation(); rollDiceChoice(1)">1 Die</button>
            <button class="dice-choice-btn" onclick="event.stopPropagation(); rollDiceChoice(2)">2 Dice</button>
            <button class="dice-choice-btn" onclick="event.stopPropagation(); rollDiceChoice('sexy')">Spicy</button>
        </div>
    `;
}

function rollDiceChoice(count) {
    currentDiceCount = count;
    const container = document.getElementById('dicePopoverContainer');
    
    container.innerHTML = document.getElementById('diceTemplate').innerHTML;
    
    const die1 = container.querySelector('#die1');
    const die2 = container.querySelector('#die2');
    
    if (count === 1 && die2) {
        die2.style.display = 'none';
    }
    
    if (die1) {
        die1.onclick = (e) => {
            e.stopPropagation();
            playSoundIfEnabled('/dice-roll.m4r');
            setTimeout(() => rollDice(), 300);
        };
    }
    
    if (die2 && count !== 1) {
        die2.onclick = (e) => {
            e.stopPropagation();
            playSoundIfEnabled('/dice-roll.m4r');
            setTimeout(() => rollDice(), 300);
        };
    }
    
    if (count === 'sexy') {
        setupSexyDice();
    } else {
        if (gameData.currentPlayerGender) {
            setDiceColor(gameData.currentPlayerGender);
        }
        initializeDicePosition();
    }
    
    playSoundIfEnabled('/dice-roll.m4r');
    setTimeout(() => rollDice(), 500);
}

function rollDice() {
    if (isDiceRolling) return;
    
    isDiceRolling = true;
    
    let die1Value = Math.floor(Math.random() * 6) + 1;
    let die2Value = currentDiceCount === 2 || currentDiceCount === 'sexy' 
        ? Math.floor(Math.random() * 6) + 1 : 0;
    
    const die1 = document.getElementById('die1');
    const die2 = document.getElementById('die2');
    
    if (die1) {
        const extraSpins1 = Math.floor(Math.random() * 3) + 2;
        const finalRotation1 = getDieRotationForValue(die1Value);
        die1.style.transform = `rotateX(${finalRotation1.x + (extraSpins1 * 360)}deg) rotateY(${finalRotation1.y + (extraSpins1 * 360)}deg)`;
    }
    
    if ((currentDiceCount === 2 || currentDiceCount === 'sexy') && die2) {
        const extraSpins2 = Math.floor(Math.random() * 3) + 3;
        const finalRotation2 = getDieRotationForValue(die2Value);
        die2.style.transform = `rotateX(${finalRotation2.x + (extraSpins2 * 360)}deg) rotateY(${finalRotation2.y + (extraSpins2 * 360)}deg)`;
    }
    
    setTimeout(() => {
        isDiceRolling = false;
        
        // If curse callback, check condition
        if (curseDiceCallback) {
            const total = die1Value + die2Value;
            curseDiceCallback(die1Value, die2Value, total);
        }
    }, 1000);
}

function getDieRotationForValue(value) {
    const rotations = {
        1: { x: 0, y: 0 },
        2: { x: -90, y: 0 },
        3: { x: 0, y: 90 },
        4: { x: 0, y: -90 },
        5: { x: 90, y: 0 },
        6: { x: 0, y: 180 }
    };
    return rotations[value];
}

function setDieRotation(die, value) {
    const rotation = getDieRotationForValue(value);
    die.style.transform = `rotateX(${rotation.x}deg) rotateY(${rotation.y}deg)`;
}

function initializeDicePosition() {
    const die1 = document.getElementById('die1');
    const die2 = document.getElementById('die2');
    
    if (die1) setDieRotation(die1, 1);
    if (die2 && currentDiceCount !== 1) setDieRotation(die2, 1);
}

function setDiceColor(gender) {
    document.querySelectorAll('.die').forEach(die => {
        die.className = `die ${gender}`;
        if (die.id === 'die2') {
            die.classList.add('two');
        }
    });
}

function setupSexyDice() {
    const die1 = document.getElementById('die1');
    const die2 = document.getElementById('die2');
    
    if (!die1 || !die2) return;
    
    const actions = ['Rub', 'Pinch', 'Kiss', 'Lick', 'Suck', 'Do Whatever You Want to'];
    const bodyParts = gameData.currentPlayerGender === 'female' 
        ? ['His Booty', 'His Neck', 'His Nipples', 'Your Choice', 'His Penis', 'His Balls']
        : ['Her Booty', 'Her Neck', 'Her Boobs', 'Her Nipples', 'Your Choice', 'Her Vagina'];
    
    const die1Faces = die1.querySelectorAll('.die-face');
    die1Faces.forEach((face, index) => {
        face.classList.add('sexy');
        face.innerHTML = `<div class="die-text">${actions[index]}</div>`;
    });
    
    const die2Faces = die2.querySelectorAll('.die-face');
    die2Faces.forEach((face, index) => {
        face.classList.add('sexy');
        face.innerHTML = `<div class="die-text">${bodyParts[index]}</div>`;
    });
    
    if (gameData.currentPlayerGender) {
        setDiceColor(gameData.currentPlayerGender);
    }
    
    initializeDicePosition();
}

function toggleDebugPanel() {
    const panel = document.getElementById('debugPanel');
    const toggle = document.getElementById('debugToggle');
    if (panel) {
        panel.classList.toggle('open');
        toggle.classList.toggle('open');

        if (panel.classList.contains('open')) {
            updateDebugPanel();
        }
    }
}

function updateDebugPanel() {
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=get_debug_info'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Update deck counts
            const countsDiv = document.getElementById('debugDeckCounts');
            if (countsDiv) {
                countsDiv.innerHTML = `
                    <div class="debug-row">
                        <span class="debug-label">Challenge Cards:</span>
                        <span class="debug-value">${data.available.challenge} / ${data.total.challenge}</span>
                    </div>
                    <div class="debug-row">
                        <span class="debug-label">Curse Cards:</span>
                        <span class="debug-value">${data.available.curse} / ${data.total.curse}</span>
                    </div>
                    <div class="debug-row">
                        <span class="debug-label">Power Cards:</span>
                        <span class="debug-value">${data.available.power} / ${data.total.power}</span>
                    </div>
                    <div class="debug-row">
                        <span class="debug-label">Battle Cards:</span>
                        <span class="debug-value">${data.total.battle}</span>
                    </div>
                `;
            }
            
            // Update deck breakdown
            const breakdownDiv = document.getElementById('debugDeckBreakdown');
            if (breakdownDiv && data.deck_breakdown) {
                let html = `<div class="debug-row"><strong>Total: ${data.deck_breakdown.total} cards</strong></div>`;
                
                let currentCategory = '';
                data.deck_breakdown.cards.forEach(card => {
                    if (card.card_category !== currentCategory) {
                        currentCategory = card.card_category;
                        html += `<div class="debug-row" style="margin-top: 10px;"><strong>${currentCategory.charAt(0).toUpperCase() + currentCategory.slice(1)} Cards:</strong></div>`;
                    }
                    html += `<div class="debug-row" style="margin-left: 15px;">
                        <span class="debug-label">${card.card_name}:</span>
                        <span class="debug-value">${card.count}</span>
                    </div>`;
                });
                breakdownDiv.innerHTML = html;
            }
        }
    });
}

function endGameDay() {
    if (!confirm('End current game day and generate next day\'s deck?')) {
        return;
    }
    
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=end_game_day_debug'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showInAppNotification('Day Ended', 'New daily deck generated');
            if (data.log) {
                const logDiv = document.getElementById('debugLog');
                if (logDiv) {
                    logDiv.textContent = data.log;
                }
            }
            loadDailyDeck();
            updateDebugPanel();
        } else {
            alert('Failed to end day: ' + (data.message || 'Unknown error'));
        }
    });
}

// Update debug panel every 10 seconds if open
setInterval(() => {
    const panel = document.getElementById('debugPanel');
    if (panel && panel.classList.contains('open')) {
        updateDebugPanel();
    }
}, 10000);

// Make functions global
window.toggleDebugPanel = toggleDebugPanel;
window.endGameDay = endGameDay;

// ========================================
// MAKE FUNCTIONS GLOBALLY AVAILABLE
// ========================================

// Core Travel Edition functions
window.handleSlotInteraction = handleSlotInteraction;
window.toggleHandOverlay = toggleHandOverlay;
window.toggleHandSlotActions = toggleHandSlotActions;
window.closeHandSlotActions = closeHandSlotActions;
window.drawSnapCard = drawSnapCard;
window.drawSpicyCard = drawSpicyCard;
window.toggleScoreBugExpanded = toggleScoreBugExpanded;
window.adjustScore = adjustScore;
window.stealPoints = stealPoints;
window.drawAllSlots = drawAllSlots;
window.animateCardToHand = animateCardToHand;

// Dice functions
window.openDicePopover = openDicePopover;
window.closeDicePopover = closeDicePopover;
window.rollDiceChoice = rollDiceChoice;

// Modal functions
window.openNotifyModal = openNotifyModal;
window.openTimerModal = openTimerModal;
window.openHistoryModal = openHistoryModal;
window.openEndGameModal = openEndGameModal;
window.closeModal = closeModal;

// Game action functions
window.createTimer = createTimer;
window.sendBump = sendBump;
window.testNotification = testNotification;
window.endGame = endGame;
window.readyForNewGame = readyForNewGame;

// Notification functions
window.enableNotifications = enableNotifications;
window.enableNotificationsFromModal = enableNotificationsFromModal;

// Card action functions
window.completeChallenge = completeChallenge;
window.vetoChallenge = vetoChallenge;
window.completeStoredChallenge = completeStoredChallenge;
window.vetoStoredChallenge = vetoStoredChallenge;
window.activateCurse = activateCurse;
window.claimPower = claimPower;
window.activatePower = activatePower;
window.discardPower = discardPower;
window.winBattle = winBattle;
window.loseBattle = loseBattle;

// Modal close functions
window.closeHandCardActions = closeHandCardActions;

// Hand card functions
window.playPowerCard = playPowerCard;
window.completeSnapCard = completeSnapCard;
window.completeSpicyCard = completeSpicyCard;
window.vetoSnapCard = vetoSnapCard;
window.vetoSpicyCard = vetoSpicyCard;