let isDarkMode = false;
let windowElements = [];
let lightInterval;

// Check system preference for dark mode
function getSystemDarkMode() {
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
}

// Initialize windows
function createWindows() {
    const windowsContainer = document.getElementById('windows');
    windowsContainer.innerHTML = '';
    
    for (let i = 0; i < 40; i++) {
        const window = document.createElement('div');
        window.className = 'window light';
        windowsContainer.appendChild(window);
        windowElements.push(window);
    }
}

// Apply dark or light mode
function applyMode(darkMode) {
    isDarkMode = darkMode;
    
    // Handle sun/moon visibility
    const sun = document.getElementById('sun');
    const moon = document.getElementById('moon');
    
    if (isDarkMode) {
        sun.classList.add('hidden');
        moon.classList.add('visible');
    } else {
        sun.classList.remove('hidden');
        moon.classList.remove('visible');
    }
}

// Random window lighting for dark mode
function startWindowLighting() {
    if (lightInterval) clearInterval(lightInterval);
    
    lightInterval = setInterval(() => {
        if (isDarkMode) {
            windowElements.forEach(window => {
                if (Math.random() < 0.1) { // 10% chance to toggle
                    window.classList.toggle('lit');
                }
            });
        }
    }, 2000);
}

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    createWindows();
    
    // Check system preference on load
    const systemDarkMode = getSystemDarkMode();
    applyMode(systemDarkMode);
    
    // Listen for system theme changes
    if (window.matchMedia) {
        const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
        mediaQuery.addEventListener('change', function(e) {
            applyMode(e.matches);
        });
    }
    
    startWindowLighting();
});