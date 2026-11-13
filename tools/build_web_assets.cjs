/** Reproducible local assets: runtime pages do not download a CSS compiler or mutable JS. */
const fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const root=path.resolve(__dirname,'..');
const output=path.join(root,'assets/js/vendor');fs.mkdirSync(output,{recursive:true});
const dependencies=path.join(root,'.tools/ui-assets/node_modules');
for(const [source,target] of [['lucide/dist/umd/lucide.js','lucide-0.468.0.js'],['jquery/dist/jquery.min.js','jquery-3.7.1.min.js'],['datatables.net/js/jquery.dataTables.min.js','jquery.dataTables-1.13.7.min.js'],['datatables.net-responsive/js/dataTables.responsive.min.js','dataTables.responsive-2.5.0.min.js']])fs.copyFileSync(path.join(dependencies,source),path.join(output,target));
const directory=path.join(root,'.tools/web-assets');fs.mkdirSync(directory,{recursive:true});
const colors=Object.fromEntries([50,100,200,300,400,500,600,700,800,900].map(n=>[n,`var(--brand-${n})`]));
const config={content:[path.join(root,'app/Views/**/*.php').replaceAll('\\','/'),path.join(root,'assets/js/*.js').replaceAll('\\','/')],theme:{extend:{fontFamily:{sans:['Inter','Segoe UI','sans-serif']},fontSize:{xs:['var(--ui-text-xs, 0.75rem)',{lineHeight:'var(--ui-line-xs, 1rem)'}],sm:['var(--ui-text-sm, 0.875rem)',{lineHeight:'var(--ui-line-sm, 1.25rem)'}]},colors:{brand:colors}}}};
fs.writeFileSync(path.join(directory,'tailwind.config.cjs'),'module.exports='+JSON.stringify(config));
fs.writeFileSync(path.join(directory,'input.css'),'@tailwind base;\n@tailwind components;\n@tailwind utilities;\n');
execFileSync(process.execPath,[path.join(dependencies,'tailwindcss/lib/cli.js'),'-c',path.join(directory,'tailwind.config.cjs'),'-i',path.join(directory,'input.css'),'-o',path.join(root,'assets/css/application.css'),'--minify'],{windowsHide:true,stdio:'inherit'});
for(const name of ['lucide','jquery','datatables.net','datatables.net-responsive','tailwindcss']){const dir=path.join(dependencies,name);const file=fs.readdirSync(dir).find(n=>/^licen[sc]e(?:\.txt|\.md)?$/i.test(n));if(file)fs.copyFileSync(path.join(dir,file),path.join(output,name+'-LICENSE.txt'));}
console.log('Local CSS and pinned UI libraries built.');
