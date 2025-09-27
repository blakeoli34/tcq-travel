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
        if (!hasClicked) {
            hasClicked = true;
            actionSound.play().catch(() => {});
            console.log('Sounds enabled');
            $(document).off('click');
        }
    });
    
    // Get game data from PHP
    if (typeof window.gameDataFromPHP !== 'undefined') {
        gameData = window.gameDataFromPHP;
        console.log('Game data:', gameData);
    }
    
    // Initialize Firebase
    initializeFirebase();
    checkBadgeSupport();
    
    // Setup event handlers
    setupModeButtons();
    setupModalHandlers();
    setupScoreBugHandlers();
    
    setTimeout(() => {
        if (!checkDowntime()) {
            loadDailyDeck();
        }
        checkVetoWait();
        updateStatusEffects();
        loadHandCards();
        updateDeckCounts();
    }, 500);
    
    // Periodic updates
    setInterval(() => {
        if (!isVetoWaiting && !checkDowntime()) {
            loadDailyDeck();
        }
        checkVetoWait();
        updateStatusEffects();
        refreshGameData();
    }, 5000);
    
    // Setup polling for waiting screens
    setupWaitingScreenPolling();
    
    // Clear badge when app becomes visible
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            updateAppBadge(0);
        }
    });

    // Touch gesture handling for hand overlay
    let touchStartY = 0;
    let touchEndY = 0;

    $(document).on('touchstart', function(e) {
        // Don't handle if touching the score bug
        if ($(e.target).closest('#scoreBug, #scoreBugExpanded').length > 0) {
            return;
        }
        touchStartY = e.originalEvent.changedTouches[0].screenY;
    });

    $(document).on('touchend', function(e) {
        // Don't handle if touching the score bug
        if ($(e.target).closest('#scoreBug, #scoreBugExpanded').length > 0) {
            return;
        }
        
        touchEndY = e.originalEvent.changedTouches[0].screenY;
        handleSwipe();
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

// ========================================
// DAILY DECK MANAGEMENT
// ========================================

let currentDeckState = null;

function loadDailyDeck() {
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
                updateDailyDeckDisplay(data.slots);
                updateDeckMessage(data.slots);
            }
        } else {
            // No deck for today - generate one
            generateDailyDeck();
        }
        updateDailyDeckCount();
    })
    .catch(error => {
        console.error('Error loading daily deck:', error);
    });
}

function checkDowntime() {
    const timezone = 'America/Indiana/Indianapolis';
    const now = new Date();
    const indianaTime = new Date(now.toLocaleString("en-US", {timeZone: timezone}));
    const hours = indianaTime.getHours();
    
    // Downtime is midnight (0) to 8am
    if (hours >= 0 && hours < 8) {
        const nextAvailable = new Date(indianaTime);
        nextAvailable.setHours(8, 0, 0, 0);
        
        showDowntimeOverlay(nextAvailable);
        return true;
    }
    
    hideDowntimeOverlay();
    return false;
}

function showDowntimeOverlay(nextAvailable) {
    const container = document.querySelector('.daily-deck-container');
    if (!container) return;
    
    container.classList.add('downtime');
    
    let overlay = document.getElementById('downtimeOverlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'downtimeOverlay';
        overlay.className = 'downtime-overlay';
        overlay.innerHTML = `
            <div class="downtime-message">Daily Deck Unavailable</div>
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
    
    const now = new Date();
    const diff = nextAvailable - now;
    
    if (diff <= 0) {
        return false;
    }
    
    const hours = Math.floor(diff / (1000 * 60 * 60));
    const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
    const seconds = Math.floor((diff % (1000 * 60)) / 1000);
    
    countdown.textContent = `${hours}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
    return true;
}

function hideDowntimeOverlay() {
    const container = document.querySelector('.daily-deck-container');
    const overlay = document.getElementById('downtimeOverlay');
    
    if (container) container.classList.remove('downtime');
    if (overlay) overlay.style.display = 'none';
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
        
        if (slot.card_id) {
            // Slot has a card
            slotElement.classList.add('has-card');
            slotContent.innerHTML = createSlotCardHTML(slot);
        } else {
            // Empty slot
            slotElement.classList.remove('has-card');
            slotContent.innerHTML = '<div class="empty-slot">TAP TO DRAW A CARD</div>';
        }
    });

    document.querySelector('.daily-deck-container')?.classList.add('loaded');
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
            if (countEl && containerEl) {
                countEl.textContent = `Cards remaining: ${data.remaining}`;
                containerEl.style.display = data.remaining > 0 ? 'block' : 'none';
            }
        }
    });
}

function animateCardDraw(slotElement) {
    const cardContent = slotElement.querySelector('.card-content');
    if (cardContent) {
        cardContent.classList.add('card-draw-animation');
        setTimeout(() => {
            cardContent.classList.remove('card-draw-animation');
        }, 600);
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
    
    const actions = getSlotActions(slot.card_category);
    const actionsHTML = `
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

function updateDeckMessage(slots) {
    const deckMessage = document.getElementById('deckMessage');
    if (!deckMessage) return;
    
    const emptySlots = slots.filter(slot => !slot.card_id).length;
    
    if (emptySlots === 3) {
        deckMessage.textContent = "Draw your first 3 cards from today's Daily Deck";
        deckMessage.style.display = 'block';
    } else if (emptySlots > 0) {
        deckMessage.style.display = 'none';
    } else {
        deckMessage.style.display = 'none';
    }
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
        toggleSlotActions(slotNumber);
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
            loadDailyDeck();
            updateDailyDeckCount();
            
            // Animate the slot after deck loads
            setTimeout(() => {
                const slotEl = document.querySelector(`.daily-slot[data-slot="${slotNumber}"]`);
                if (slotEl) animateCardDraw(slotEl);
            }, 100);
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

// Close slot actions when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('.daily-slot')) {
        document.querySelectorAll('.daily-slot.expanded').forEach(slot => {
            slot.classList.remove('expanded');
        });
    }

    if (handOverlayOpen && 
        !e.target.closest('#handOverlay') && 
        !e.target.closest('#handIndicator')) {
        toggleHandOverlay();
    }
});

// ========================================
// CARD ACTIONS
// ========================================

function completeChallenge(slotNumber) {
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=complete_challenge&slot_number=${slotNumber}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            playSoundIfEnabled('/card-completed.m4r');
            if (data.points_awarded) {
                showInAppNotification('Challenge Complete!', `Earned ${data.points_awarded} points`);
                // Call refreshGameData directly instead of updateScore
                setTimeout(() => refreshGameData(), 1000);
            }
            loadDailyDeck();
        } else {
            alert('Failed to complete challenge: ' + data.message);
        }
    });
}

function vetoChallenge(slotNumber) {
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=veto_challenge&slot_number=${slotNumber}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            playSoundIfEnabled('/card-vetoed.m4r');
            if (data.penalties && data.penalties.length > 0) {
                showInAppNotification('Challenge Vetoed', data.penalties.join(', '));
            }
            loadDailyDeck();
            setTimeout(() => refreshGameData(), 1500);
        } else {
            alert('Failed to veto challenge: ' + data.message);
        }
    });
}

function activateCurse(slotNumber) {
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=activate_curse&slot_number=${slotNumber}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            playSoundIfEnabled('/card-curse.m4r');
            showInAppNotification('Curse Activated!', 'Check your status effects');
            loadDailyDeck();
            setTimeout(() => updateStatusEffects(), 1000);
        } else {
            alert('Failed to activate curse: ' + data.message);
        }
    });
}

function claimPower(slotNumber) {
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=claim_power&slot_number=${slotNumber}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            playSoundIfEnabled('/card-power.m4r');
            showInAppNotification('Power Claimed!', data.message);
            loadDailyDeck();
            setTimeout(() => loadHandCards(), 1000);
        } else {
            alert('Failed to claim power: ' + data.message);
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
            showInAppNotification('Power Discarded', data.message);
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
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=complete_battle&slot_number=${slotNumber}&is_winner=${isWinner}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            playSoundIfEnabled('/card-battle.m4r');
            if (data.results && data.results.length > 0) {
                showInAppNotification('Battle Complete!', data.results.join(', '));
            }
            loadDailyDeck();
            setTimeout(() => refreshGameData(), 1500);
        } else {
            alert('Failed to complete battle: ' + data.message);
        }
    });
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

function toggleHandOverlay() {
    const overlay = document.getElementById('handOverlay');
    if (!overlay) return;
    
    handOverlayOpen = !handOverlayOpen;
    
    if (handOverlayOpen) {
        loadHandCards();
        overlay.classList.add('active');
        setOverlayActive(true);
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
            updateHandSlots();
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
            if (data.effects && data.effects.length > 0) {
                showInAppNotification('Power Played!', data.effects.join(', '));
            }
            loadHandCards();
            setTimeout(() => {
                refreshGameData();
                updateStatusEffects();
            }, 1500);
        } else {
            alert('Failed to play power card: ' + data.message);
        }
    });
}

function completeSnapCard(playerCardId) {
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=complete_snap_card&player_card_id=${playerCardId}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            playSoundIfEnabled('/card-completed.m4r');
            if (data.effects && data.effects.length > 0) {
                showInAppNotification('Snap Complete!', data.effects.join(', '));
            }
            if (data.points_awarded) {
                setTimeout(() => updateScore(gameData.currentPlayerId, data.points_awarded), 1000);
            }
            loadHandCards();
            setTimeout(() => updateStatusEffects(), 1500);
        } else {
            alert('Failed to complete snap card: ' + data.message);
        }
    });
}

function completeSpicyCard(playerCardId) {
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=complete_spicy_card&player_card_id=${playerCardId}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            playSoundIfEnabled('/card-completed.m4r');
            if (data.effects && data.effects.length > 0) {
                showInAppNotification('Spicy Complete!', data.effects.join(', '));
            }
            if (data.points_awarded) {
                setTimeout(() => updateScore(gameData.currentPlayerId, data.points_awarded), 1000);
            }
            loadHandCards();
            setTimeout(() => updateStatusEffects(), 1500);
        } else {
            alert('Failed to complete spicy card: ' + data.message);
        }
    });
}

function vetoSnapCard(playerCardId) {
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=veto_snap_card&player_card_id=${playerCardId}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            playSoundIfEnabled('/card-vetoed.m4r');
            if (data.penalties && data.penalties.length > 0) {
                showInAppNotification('Snap Vetoed', data.penalties.join(', '));
            }
            loadHandCards();
            setTimeout(() => refreshGameData(), 1500);
        } else {
            alert('Failed to veto snap card: ' + data.message);
        }
    });
}

function vetoSpicyCard(playerCardId) {
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=veto_spicy_card&player_card_id=${playerCardId}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            playSoundIfEnabled('/card-vetoed.m4r');
            if (data.penalties && data.penalties.length > 0) {
                showInAppNotification('Spicy Vetoed', data.penalties.join(', '));
            }
            loadHandCards();
            setTimeout(() => refreshGameData(), 1500);
        } else {
            alert('Failed to veto spicy card: ' + data.message);
        }
    });
}

function drawSnapCard() {
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=draw_snap_card'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showInAppNotification('Card Drawn!', data.message);
            loadHandCards();
        } else {
            alert('Failed to draw snap card: ' + data.message);
        }
    });
}

function drawSpicyCard() {
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=draw_spicy_card'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showInAppNotification('Card Drawn!', data.message);
            loadHandCards();
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
        slot.classList.remove('filled', 'expanded');
        slot.classList.add('empty');
        slot.innerHTML = '<div class="empty-slot-indicator">Empty</div>';
    });
    
    // Update hand count
    const totalCards = handCards.reduce((sum, card) => sum + card.quantity, 0);
    if (handCountEl) handCountEl.textContent = totalCards;
    
    // Fill slots with cards
    let slotIndex = 0;
    handCards.forEach(card => {
        for (let i = 0; i < card.quantity && slotIndex < 6; i++) {
            const slot = slots[slotIndex];
            slot.classList.remove('empty');
            slot.classList.add('filled');
            
            slot.innerHTML = createHandCardHTML(card, slotIndex);
            slotIndex++;
        }
    });
}

function createHandCardHTML(card, slotIndex) {
    const cardTypeIcons = {
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
        'power': 'fa-star',
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
            if (data.is_waiting && !isVetoWaiting) {
                startVetoWaitDisplay(data.wait_until);
            } else if (!data.is_waiting && isVetoWaiting) {
                endVetoWaitDisplay();
            }
        }
    });
}

function startVetoWaitDisplay(waitUntil) {
    isVetoWaiting = true;
    
    // Parse the MySQL datetime string directly (it's already in local Indiana time)
    vetoWaitEndTime = new Date(waitUntil.replace(' ', 'T'));

    $('.daily-deck-container').addClass('wait');
    
    const overlay = document.getElementById('vetoWaitOverlay');
    if (overlay) {
        overlay.style.display = 'flex';
        updateVetoWaitCountdown();
        
        const countdownInterval = setInterval(() => {
            if (!isVetoWaiting) {
                clearInterval(countdownInterval);
                return;
            }
            updateVetoWaitCountdown();
        }, 1000);
    }
}

function updateVetoWaitCountdown() {
    const countdown = document.getElementById('vetoCountdown');
    if (!countdown || !vetoWaitEndTime) return;
    
    const now = new Date();
    const diff = vetoWaitEndTime - now;
    
    if (diff <= 0) {
        endVetoWaitDisplay();
        return;
    }
    
    const minutes = Math.floor(diff / (1000 * 60));
    const seconds = Math.floor((diff % (1000 * 60)) / 1000);
    
    countdown.textContent = `${minutes}:${seconds.toString().padStart(2, '0')}`;
}

function endVetoWaitDisplay() {
    isVetoWaiting = false;
    vetoWaitEndTime = null;

    $('.daily-deck-container').removeClass('wait');
    
    const overlay = document.getElementById('vetoWaitOverlay');
    if (overlay) {
        overlay.style.display = 'none';
    }
}

// ========================================
// STATUS EFFECTS SYSTEM
// ========================================

function updateStatusEffects() {
    
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

function showActiveEffectsPopover() {
    console.log('showActiveEffectsPopover called'); // Debug log
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=get_active_effects_details'
    })
    .then(response => response.json())
    .then(data => {
        console.log('Effects data:', data); // Debug log
        if (data.success) {
            createEffectsPopover(data.curse_effects, data.power_effects);
        }
    });
}

function createEffectsPopover(curseEffects, powerEffects) {
    const popover = document.createElement('div');
    popover.className = 'effects-popover';
    popover.innerHTML = `
        <div class="effects-popover-content">
            <h4>Active Effects</h4>
            ${curseEffects.map(effect => `
                <div class="effect-item curse-effect">
                    <i class="fa-solid fa-skull-crossbones"></i>
                    <div class="effect-details">
                        <div class="effect-name">${effect.card_name}</div>
                        <div class="effect-description">${effect.card_description}</div>
                        ${effect.expires_at ? `<div class="effect-expires">Expires: ${new Date(effect.expires_at).toLocaleTimeString()}</div>` : ''}
                    </div>
                </div>
            `).join('')}
            ${powerEffects.map(effect => `
                <div class="effect-item power-effect">
                    <i class="fa-solid fa-star"></i>
                    <div class="effect-details">
                        <div class="effect-name">${effect.card_name}</div>
                        <div class="effect-description">${effect.card_description}</div>
                        ${effect.expires_at ? `<div class="effect-expires">Expires: ${new Date(effect.expires_at).toLocaleTimeString()}</div>` : ''}
                    </div>
                </div>
            `).join('')}
            <button onclick="closeEffectsPopover()">Close</button>
        </div>
    `;
    
    document.body.appendChild(popover);
    setTimeout(() => popover.classList.add('active'), 10);
}

function closeEffectsPopover() {
    const popover = document.querySelector('.effects-popover');
    if (popover) popover.remove();
}

function displayStatusEffects(containerId, effects) {
    const container = document.getElementById(containerId);
    if (!container) return;
    
    container.innerHTML = '';
    
    effects.forEach(effect => {
        const span = document.createElement('span');
        span.className = 'status-effect';
        span.style.backgroundColor = effect.color;
        span.style.cursor = 'pointer';
        span.addEventListener('click', function(e) {
            e.stopPropagation();
            console.log('Status effect clicked');
            showActiveEffectsPopover();
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
        // Add swipe up gesture
        let touchStartY = 0;
        scoreBug.addEventListener('touchstart', function(e) {
            touchStartY = e.touches[0].clientY;
        });
        
        scoreBug.addEventListener('touchend', function(e) {
            const touchEndY = e.changedTouches[0].clientY;
            const swipeDistance = touchStartY - touchEndY;
            
            if (swipeDistance > 50) { // Swipe up
                toggleScoreBugExpanded();
            }
        });
    }
    
    // Close when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('#scoreBug') && scoreBug && scoreBug.classList.contains('expanded')) {
            toggleScoreBugExpanded();
        }
    });
}

function toggleScoreBugExpanded() {
    const scoreBug = document.getElementById('scoreBug');
    const expandIcon = document.getElementById('expandIcon');
    const expandedContent = document.querySelector('.score-bug-expanded-content');
    
    if (!scoreBug || !expandIcon || !expandedContent) return;
    
    const isExpanded = scoreBug.classList.contains('expanded');
    
    if (!isExpanded) {
        scoreBug.classList.add('expanded');
        
        // Calculate and set height
        const contentHeight = expandedContent.scrollHeight;
        scoreBug.style.height = `${120 + contentHeight}px`;
        
        expandIcon.className = 'fa-solid fa-chevron-down';
        loadAwardsInfo();
        setOverlayActive(true);
        scoreBugExpanded = true;
    } else {
        scoreBug.classList.remove('expanded');
        scoreBug.style.height = '';
        expandIcon.className = 'fa-solid fa-chevron-up';
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
    // Update snap award progress
    const snapProgress = document.querySelector('.award-item:first-child .award-progress');
    if (snapProgress && data.next_snap_level) {
        snapProgress.textContent = `${data.next_snap_level.cards_needed}/${data.next_snap_level.next_level} TO NEXT LEVEL`;
    }
    
    // Update spicy award progress
    const spicyProgress = document.querySelector('.award-item:last-child .award-progress');
    if (spicyProgress && data.next_spicy_level) {
        spicyProgress.textContent = `${data.next_spicy_level.cards_needed}/${data.next_spicy_level.next_level} TO NEXT LEVEL`;
    }
    
    // Update challenge master
    const playerChallengeCount = document.getElementById('playerChallengeCount');
    const opponentChallengeCount = document.getElementById('opponentChallengeCount');
    
    if (playerChallengeCount && data.player_stats) {
        playerChallengeCount.textContent = data.player_stats.challenges_completed;
    }
    
    if (opponentChallengeCount && data.game_stats && data.game_stats.player_stats.length > 1) {
        const opponentStats = data.game_stats.player_stats.find(p => p.player_id !== gameData.currentPlayerId);
        if (opponentStats) {
            opponentChallengeCount.textContent = opponentStats.challenges_completed;
        }
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
        position: absolute;
        bottom: 10px;
        ${isCurrentPlayer ? 'right: 20px;' : 'left: 20px;'}
        font-size: 24px;
        font-weight: 900;
        color: #3c3c3c;
        pointer-events: none;
        z-index: 1001;
        opacity: 0;
    `;
    scoreBug.appendChild(flyout);
    
    // Animate flyout
    setTimeout(() => {
        flyout.style.transition = 'all 2000ms cubic-bezier(0.25, 0.46, 0.45, 0.94)';
        flyout.style.opacity = '1';
        flyout.style.transform = 'translateY(-120px)';
        
        setTimeout(() => {
            flyout.style.opacity = '0';
            setTimeout(() => flyout.remove(), 300);
        }, 1500);
    }, 10);
    
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
}

function isSoundEnabled() {
    return localStorage.getItem('couples_quest_sound_enabled') !== 'false';
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
                if (data.success && data.status !== 'waiting') {
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
window.activateCurse = activateCurse;
window.claimPower = claimPower;
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