const esbuild = require('esbuild');
const isWatch = process.argv.includes('--watch');

/** @type {import("esbuild").BuildOptions} */
const options = {
	logLevel: "info",
	bundle: true,
	minify: true,
	target: "es2020",
	sourcemap: 'linked',
	entryPoints: {"BackendModule": "src/index.tsx"},
	loader: {
		".js": "tsx",
	},
	outdir: "../../Public/BackendModule",
}

if (isWatch) {
	esbuild.context(options).then((ctx) => ctx.watch())
} else {
	esbuild.build(options)
}
