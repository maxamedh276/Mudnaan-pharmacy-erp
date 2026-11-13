/** Compile a stable offline copy of the existing UI styles for screen recording. */
const fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const directory=path.resolve('.tools/pharmacy-training');fs.mkdirSync(directory,{recursive:true});
const configuration={content:[path.resolve('app/Views/**/*.php').replaceAll('\\','/'),path.resolve('assets/js/*.js').replaceAll('\\','/')],theme:{extend:{fontFamily:{sans:['Inter','sans-serif']},fontSize:{xs:['var(--ui-text-xs, 0.75rem)',{lineHeight:'var(--ui-line-xs, 1rem)'}],sm:['var(--ui-text-sm, 0.875rem)',{lineHeight:'var(--ui-line-sm, 1.25rem)'}]},colors:{brand:{50:'#eff6ff',100:'#dbeafe',200:'#bfdbfe',300:'#93c5fd',400:'#60a5fa',500:'#3b82f6',600:'#1967d2',700:'#1d4ed8',800:'#1e40af',900:'#1e3a8a'}}}}};
fs.writeFileSync(path.join(directory,'tailwind.config.cjs'),'module.exports='+JSON.stringify(configuration));
fs.writeFileSync(path.join(directory,'input.css'),'@tailwind base;\n@tailwind components;\n@tailwind utilities;\n');
execFileSync(process.execPath,[path.resolve('.tools/ui-assets/node_modules/tailwindcss/lib/cli.js'),'-c',path.join(directory,'tailwind.config.cjs'),'-i',path.join(directory,'input.css'),'-o',path.join(directory,'tailwind.css'),'--minify'],{windowsHide:true,stdio:'inherit'});
