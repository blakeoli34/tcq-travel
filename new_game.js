// Travel Edition JavaScript - Key functions to add to game.js

// ========================================
// DAILY DECK MANAGEMENT
// ========================================

let dailyDeckData = {
    slots: [],
    remainingCards: 0
};

let isVetoWaiting = false;
let vetoWaitEndTime = null;

// Load and display daily deck
function loadDailyDeck() {
    if (!document.body.classList.contains('digital')) return;
    
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=get_daily_deck'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            dailyDeckData = data;
            updateDailyDeckDisplay(data.slots);
            updateDeckMessage(data.slots);
        } else {
            // No deck for today - generate one
            generateDailyDeck();
        }
    })
    .catch(error => {
        console.error('Error loading daily deck:', error);
    });
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
}

function createSlotCardHTML(slot) {
    const cardTypeIcons = {
        'challenge': 'fa-trophy',
        'curse': 'fa-skull',
        'power': 'fa-bolt',
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
    
    return `
        <div class="card-content">
            <div class="card-header">
                <div class="card-type-icon ${slot.card_category}">
                    <i class="fa-solid ${icon}"></i>
                </div>
                <div class="card-info">
                    <div class="card-name">${slot.card_name}</div>
                    <div class="card-description">${slot.card_description}</div>
                </div>
            </div>
            <div class="card-meta">
                ${badges}
            </div>
        </div>
    `;
}

function updateDeckMessage(slots) {
    const deckMessage = document.getElementById('deckMessage');
    if (!deckMessage) return;
    
    const emptySlots = slots.filter(slot => !slot.card_id).length;
    
    if (emptySlots === 3) {
        deckMessage.textContent = "Draw your first 3 cards from today's Daily Deck";
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
        // Empty slot - draw a card
        drawToSlot(slotNumber);
    } else {
        // Slot has card - show action options
        showSlotActions(slotNumber, slot);
    }
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
            loadDailyDeck(); // Refresh display
        } else {
            alert('Failed to draw card: ' + (data.message || 'Unknown error'));
        }
    });
}

function showSlotActions(slotNumber, slot) {
    const actions = getSlotActions(slot.card_category);
    
    // Create action modal
    const modal = document.createElement('div');
    modal.className = 'modal active';
    modal.innerHTML = `
        <div class="modal-content">
            <div class="modal-title">${slot.card_name}</div>
            <div class="modal-subtitle">${slot.card_description}</div>
            ${actions.map(action => `
                <button class="btn ${action.class || ''}" onclick="${action.onClick}(${slotNumber}); closeSlotActions()">
                    ${action.text}
                </button>
            `).join('')}
            <button class="btn btn-secondary" onclick="closeSlotActions()">Cancel</button>
        </div>
    `;
    
    document.body.appendChild(modal);
    modal.id = 'slotActionsModal';
    setOverlayActive(true);
}

function getSlotActions(cardCategory) {
    switch (cardCategory) {
        case 'challenge':
            return [
                { text: 'Complete Challenge', onClick: 'completeChallenge' },
                { text: 'Veto Challenge', onClick: 'vetoChallenge', class: 'btn-secondary' }
            ];
        case 'curse':
            return [
                { text: 'Activate Curse', onClick: 'activateCurse' }
            ];
        case 'power':
            return [
                { text: 'Claim Power Card', onClick: 'claimPower' },
                { text: 'Discard Power Card', onClick: 'discardPower', class: 'btn-secondary' }
            ];
        case 'battle':
            return [
                { text: 'I Won!', onClick: 'winBattle' },
                { text: 'I Lost', onClick: 'loseBattle', class: 'btn-secondary' }
            ];
        default:
            return [];
    }
}

function closeSlotActions() {
    const modal = document.getElementById('slotActionsModal');
    if (modal) {
        modal.remove();
        setOverlayActive(false);
    }
}

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
                updateScore(gameData.currentPlayerId, data.points_awarded);
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
            refreshGameData();
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
            updateStatusEffects();
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
            loadHandCards();
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
            refreshGameData();
        } else {
            alert('Failed to complete battle: ' + data.message);
        }
    });
}

// ========================================
// HAND MANAGEMENT
// ========================================

let handOverlayOpen = false;
let currentHandDeck = 'snap';
let handCards = [];

function toggleHandOverlay() {
    const overlay = document.getElementById('handOverlay');
    if (!overlay) return;
    
    handOverlayOpen = !handOverlayOpen;
    
    if (handOverlayOpen) {
        loadHandCards();
        overlay.classList.add('active');
    } else {
        overlay.classList.remove('active');
    }
}

function selectDeck(deckType) {
    currentHandDeck = deckType;
    
    // Update deck selector
    document.querySelectorAll('.deck-option').forEach(option => {
        option.classList.remove('active');
    });
    document.querySelector(`.${deckType}-deck`).classList.add('active');
    
    // Update hand display
    displayHandCards();
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
            displayHandCards();
            updateDeckCounts();
        }
    });
}