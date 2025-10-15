/**
 * Gestion du thème clair/sombre
 */

// Applique le thème immédiatement pour éviter le flash
(function() {
	const theme = localStorage.getItem('theme') || 'light';
	document.documentElement.setAttribute('data-theme', theme);
})();

// Gestion du dark mode toggle
document.addEventListener('DOMContentLoaded', () => {
	const themeToggle = document.getElementById('theme-toggle');
	const themeIcon = themeToggle.querySelector('.theme-toggle-icon');

	function updateThemeIcon(theme) {
		themeIcon.textContent = theme === 'dark' ? '☀️' : '🌙';
	}

	// Initialisation
	const currentTheme = document.documentElement.getAttribute('data-theme');
	updateThemeIcon(currentTheme);

	// Toggle
	themeToggle.addEventListener('click', () => {
		const currentTheme = document.documentElement.getAttribute('data-theme');
		const newTheme = currentTheme === 'dark' ? 'light' : 'dark';

		document.documentElement.setAttribute('data-theme', newTheme);
		localStorage.setItem('theme', newTheme);
		updateThemeIcon(newTheme);

		// Mettre à jour les graphiques si la fonction existe
		if (typeof updateChartsTheme === 'function') {
			updateChartsTheme();
		}
	});
});
