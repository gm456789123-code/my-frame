/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "./templates/**/*.latte",
    "./src/**/*.php",
    "./public/**/*.php",
    "./themes/**/*.php",
  ],
  theme: {
    extend: {},
  },
  plugins: [],
};
