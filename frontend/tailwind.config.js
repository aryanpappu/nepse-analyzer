/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  theme: {
    extend: {
      colors: {
        // Semantic names for market direction, so components never hardcode a
        // colour and the up/down convention stays consistent everywhere.
        gain: { DEFAULT: '#16a34a', light: '#dcfce7', dark: '#166534' },
        loss: { DEFAULT: '#dc2626', light: '#fee2e2', dark: '#991b1b' },
        flat: { DEFAULT: '#64748b', light: '#f1f5f9' },
      },
    },
  },
  plugins: [],
}
