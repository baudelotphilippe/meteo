/**
 * Gestion des graphiques météo et des filtres
 */

// Fonction pour obtenir les couleurs en fonction du thème
function getChartColors() {
	const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
	return {
		gridColor: isDark ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.1)',
		textColor: isDark ? '#e0e0e0' : '#666',
		iconColor: isDark ? '#e0e0e0' : '#000'
	};
}

// Plugin Chart.js pour afficher les icônes météo
const iconPlugin = {
	id: 'weatherIcons',
	afterDatasetsDraw(chart) {
		const colors = getChartColors();
		chart.data.datasets.forEach((dataset, datasetIndex) => {
			const meta = chart.getDatasetMeta(datasetIndex);
			if (!dataset.icons || meta.hidden) return;
			meta.data.forEach((point, i) => {
				const icon = dataset.icons[i];
				if (icon && point && !isNaN(dataset.data[i])) {
					chart.ctx.save();
					chart.ctx.font = '16px sans-serif';
					chart.ctx.textAlign = 'center';
					chart.ctx.fillStyle = colors.iconColor;
					chart.ctx.fillText(icon, point.x, point.y - 20);
					chart.ctx.restore();
				}
			});
		});
	}
};

// Stockage des instances de graphiques
const chartInstances = [];

// Fonction pour créer/mettre à jour les options de graphique
function getChartOptions() {
	const colors = getChartColors();
	return {
		responsive: true,
		maintainAspectRatio: false,
		scales: {
			x: {
				ticks: {
					autoSkip: false,
					color: colors.textColor,
					font: {
						size: window.innerWidth < 600 ? 10 : 12
					}
				},
				grid: {
					color: colors.gridColor
				}
			},
			y: {
				position: 'left',
				min: 0,
				max: 40,
				ticks: {
					color: colors.textColor
				},
				grid: {
					color: colors.gridColor
				}
			},
			yRight: {
				position: 'right',
				min: 0,
				max: 40,
				ticks: {
					color: colors.textColor
				},
				grid: { drawOnChartArea: false }
			}
		},
		plugins: {
			legend: { display: false }
		}
	};
}

// Fonction pour mettre à jour tous les graphiques (appelée lors du changement de thème)
function updateChartsTheme() {
	const newOptions = getChartOptions();
	chartInstances.forEach(chart => {
		chart.options = newOptions;
		chart.update('none'); // 'none' pour éviter l'animation
	});
}

// Initialisation des filtres de sources météo
document.addEventListener('DOMContentLoaded', () => {
	const buttons = document.querySelectorAll('.filter-btn');
	const cards = document.querySelectorAll('.provider-card');

	// Bouton "Tous"
	const allButton = document.querySelector('.filter-btn[data-target="all"]');
	if (allButton) {
		allButton.addEventListener('click', () => {
			cards.forEach(card => card.style.display = 'block');
			buttons.forEach(btn => btn.classList.remove('active'));
			allButton.classList.add('active');
		});
	}

	// Boutons individuels
	buttons.forEach(button => {
		if (button.dataset.target === 'all') return;

		button.addEventListener('click', () => {
			button.classList.toggle('active');
			if (allButton) {
				allButton.classList.remove('active');
			}

			const activeTargets = Array.from(buttons)
				.filter(btn => btn.classList.contains('active') && btn.dataset.target !== 'all')
				.map(btn => btn.dataset.target);

			cards.forEach(card => {
				const matches = activeTargets.some(target => card.classList.contains(target));
				card.style.display = matches || activeTargets.length === 0 ? 'block' : 'none';
			});
		});
	});
});
