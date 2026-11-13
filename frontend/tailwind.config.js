/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{js,jsx}'],
  darkMode: 'class',
  theme: {
    extend: {
      colors: {
        brand: {
          50: '#eef6ff', 100: '#d9ebff', 200: '#bcdbff', 300: '#8ec4ff', 400: '#59a2ff',
          500: '#3380fa', 600: '#1d62ef', 700: '#154ddc', 800: '#183fb2', 900: '#19398c',
        },
        ink: { 900: '#080d1a', 800: '#0c1323', 700: '#101727', 600: '#16203a' },
      },
      keyframes: {
        'fade-up': { from: { opacity: '0', transform: 'translateY(14px)' }, to: { opacity: '1', transform: 'translateY(0)' } },
        'fade-in': { from: { opacity: '0' }, to: { opacity: '1' } },
        'scale-in': { from: { opacity: '0', transform: 'translateY(10px) scale(.97)' }, to: { opacity: '1', transform: 'translateY(0) scale(1)' } },
        'slide-in-end': { from: { opacity: '0', transform: 'translateX(36px)' }, to: { opacity: '1', transform: 'translateX(0)' } },
        'slide-in-start': { from: { opacity: '0', transform: 'translateX(-36px)' }, to: { opacity: '1', transform: 'translateX(0)' } },
        shimmer: { '100%': { transform: 'translateX(100%)' } },
        'grow-x': { from: { transform: 'scaleX(0)' }, to: { transform: 'scaleX(1)' } },
        float: { '0%,100%': { transform: 'translateY(0)' }, '50%': { transform: 'translateY(-6px)' } },
        'pulse-ring': { '0%': { boxShadow: '0 0 0 0 rgba(52,211,153,.45)' }, '100%': { boxShadow: '0 0 0 10px rgba(52,211,153,0)' } },
      },
      animation: {
        'fade-up': 'fade-up .55s cubic-bezier(.2,.8,.2,1) both',
        'fade-in': 'fade-in .3s ease-out both',
        'scale-in': 'scale-in .28s cubic-bezier(.2,.8,.2,1) both',
        'slide-in-end': 'slide-in-end .3s cubic-bezier(.2,.8,.2,1) both',
        'slide-in-start': 'slide-in-start .3s cubic-bezier(.2,.8,.2,1) both',
        shimmer: 'shimmer 1.6s infinite',
        'grow-x': 'grow-x .9s cubic-bezier(.2,.8,.2,1) both',
        float: 'float 5s ease-in-out infinite',
        'pulse-ring': 'pulse-ring 1.8s ease-out infinite',
      },
    },
  },
  plugins: [],
};
