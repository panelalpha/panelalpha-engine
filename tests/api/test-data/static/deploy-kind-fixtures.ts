import * as fs from 'node:fs';
import * as path from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * Minimal trees that make detection claim each shipped platform and app.
 *
 * The live inspect spec drops these into a DinD account. Adding a platform
 * YAML without a fixture here fails the unit spec that diffs against
 * `core/resources/`.
 */
export interface DeployKindFixture {
  /** Recipe id (`application.platform` / `candidates[0].id`). */
  id: string;
  /** Deploy pipeline strategy (`php`, `static`, `compose`, …). */
  strategy: string;
  files: Record<string, string>;
  /**
   * Unique string the live front page must contain after a real deploy.
   * Presence here is what puts the fixture on the HTTP web-deploy path.
   * CMS stubs stay inspect-only because they cannot be built from a
   * detection tree. Ruby is inspect-only for now: archive start fails on
   * this engine with `sh: 3: Syntax error: "(" unexpected`.
   */
  pageMarker?: string;
}

const suiteRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
export const PLATFORMS_DIR = path.resolve(suiteRoot, '../../core/resources/platforms');
export const APPS_DIR = path.resolve(suiteRoot, '../../core/resources/apps');

function pkg(
  extra: Record<string, unknown> & {
    dependencies?: Record<string, string>;
    scripts?: Record<string, string>;
  } = {}
): string {
  return `${JSON.stringify(
    {
      name: 'app',
      private: true,
      ...extra,
    },
    null,
    2
  )}\n`;
}

function composer(require: Record<string, string> = { php: '>=8.1' }): string {
  return `${JSON.stringify({ name: 'app/php', require }, null, 2)}\n`;
}

const PHP = '<?php\n';
const HTML_PAGE = (title: string): string =>
  `<!doctype html><title>${title}</title><p>${title}</p>\n`;
const frontend = (id: string): string => `pae-frontend:${id}`;

function phpIndex(id: string): string {
  return `<?php header('Content-Type: text/html; charset=utf-8');\necho ${JSON.stringify(HTML_PAGE(frontend(id)))};\n`;
}

function nodeHttpServer(id: string, port = 3000): string {
  return [
    "const http = require('node:http');",
    `const port = Number(process.env.PORT) || ${port};`,
    `const html = ${JSON.stringify(HTML_PAGE(frontend(id)))};`,
    'http.createServer((_req, res) => {',
    "  res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });",
    '  res.end(html);',
    "}).listen(port, '0.0.0.0');",
    '',
  ].join('\n');
}

function nodeHttpServerEsm(id: string, port = 3000): string {
  return [
    "import http from 'node:http';",
    `const port = Number(process.env.PORT || process.env.NITRO_PORT) || ${port};`,
    `const html = ${JSON.stringify(HTML_PAGE(frontend(id)))};`,
    'http.createServer((_req, res) => {',
    "  res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });",
    '  res.end(html);',
    "}).listen(port, '0.0.0.0');",
    '',
  ].join('\n');
}

function nextAppFiles(id: string): Record<string, string> {
  return {
    'app/layout.js': [
      'export default function Root({ children }) {',
      '  return (',
      '    <html lang="en">',
      '      <body>{children}</body>',
      '    </html>',
      '  );',
      '}',
      '',
    ].join('\n'),
    'app/page.js': [
      'export default function Page() {',
      `  return <p>${frontend(id)}</p>;`,
      '}',
      '',
    ].join('\n'),
  };
}

function javaHttpServer(id: string): string {
  return [
    'package app;',
    'import com.sun.net.httpserver.HttpServer;',
    'import java.io.OutputStream;',
    'import java.net.InetSocketAddress;',
    'import java.nio.charset.StandardCharsets;',
    '',
    'public class App {',
    '    public static void main(String[] args) throws Exception {',
    `        byte[] body = ${JSON.stringify(HTML_PAGE(frontend(id)))}.getBytes(StandardCharsets.UTF_8);`,
    '        HttpServer server = HttpServer.create(new InetSocketAddress("0.0.0.0", 8080), 0);',
    '        server.createContext("/", exchange -> {',
    '            exchange.getResponseHeaders().set("Content-Type", "text/html; charset=utf-8");',
    '            exchange.sendResponseHeaders(200, body.length);',
    '            try (OutputStream os = exchange.getResponseBody()) { os.write(body); }',
    '        });',
    '        server.start();',
    '        Thread.currentThread().join();',
    '    }',
    '}',
    '',
  ].join('\n');
}

const JAVA_MAVEN_POM = `<?xml version="1.0" encoding="UTF-8"?>
<project xmlns="http://maven.apache.org/POM/4.0.0">
  <modelVersion>4.0.0</modelVersion>
  <groupId>app</groupId>
  <artifactId>app</artifactId>
  <version>1.0.0</version>
  <properties>
    <maven.compiler.release>21</maven.compiler.release>
    <project.build.sourceEncoding>UTF-8</project.build.sourceEncoding>
  </properties>
  <build>
    <plugins>
      <plugin>
        <groupId>org.apache.maven.plugins</groupId>
        <artifactId>maven-jar-plugin</artifactId>
        <version>3.4.2</version>
        <configuration>
          <archive>
            <manifest>
              <mainClass>app.App</mainClass>
            </manifest>
          </archive>
        </configuration>
      </plugin>
    </plugins>
  </build>
</project>
`;

const JAVA_GRADLE_BUILD = `plugins { id 'java' }
sourceCompatibility = 21
targetCompatibility = 21
jar {
  archiveBaseName = 'app'
  archiveVersion = '1.0.0'
  manifest { attributes 'Main-Class': 'app.App' }
}
`;

export const DEPLOY_KIND_FIXTURES: readonly DeployKindFixture[] = [
  {
    id: 'compose',
    strategy: 'compose',
    pageMarker: 'pae-frontend:compose',
    files: {
      'docker-compose.yml': 'services:\n  web:\n    build: .\n    ports:\n      - "80:80"\n',
      Dockerfile:
        'FROM nginx:alpine\nCOPY index.html /usr/share/nginx/html/index.html\nEXPOSE 80\n',
      'index.html': HTML_PAGE('pae-frontend:compose'),
    },
  },
  {
    id: 'dockerfile',
    strategy: 'dockerfile',
    pageMarker: 'pae-frontend:dockerfile',
    files: {
      Dockerfile:
        'FROM nginx:alpine\nCOPY index.html /usr/share/nginx/html/index.html\nEXPOSE 80\n',
      'index.html': HTML_PAGE('pae-frontend:dockerfile'),
    },
  },
  {
    id: 'dotnet',
    strategy: 'dotnet',
    pageMarker: frontend('dotnet'),
    files: {
      'App.csproj':
        '<Project Sdk="Microsoft.NET.Sdk.Web"><PropertyGroup><TargetFramework>net8.0</TargetFramework><ImplicitUsings>enable</ImplicitUsings></PropertyGroup></Project>\n',
      'Program.cs': [
        'var app = WebApplication.CreateBuilder(args).Build();',
        `app.MapGet("/", () => Results.Content(${JSON.stringify(HTML_PAGE(frontend('dotnet')))}, "text/html"));`,
        'app.Run();',
        '',
      ].join('\n'),
    },
  },
  {
    id: 'go',
    strategy: 'go',
    pageMarker: frontend('go'),
    files: {
      'go.mod': 'module app\n\ngo 1.22\n',
      'main.go': [
        'package main',
        '',
        'import (',
        '	"fmt"',
        '	"net/http"',
        ')',
        '',
        'func main() {',
        `	html := ${JSON.stringify(HTML_PAGE(frontend('go')))}`,
        '	http.HandleFunc("/", func(w http.ResponseWriter, _ *http.Request) {',
        '		w.Header().Set("Content-Type", "text/html; charset=utf-8")',
        '		fmt.Fprint(w, html)',
        '	})',
        '	_ = http.ListenAndServe(":8080", nil)',
        '}',
        '',
      ].join('\n'),
    },
  },
  {
    id: 'html',
    strategy: 'static',
    pageMarker: 'pae-frontend:html',
    files: {
      'home.html': HTML_PAGE('pae-frontend:html'),
      'about.html': HTML_PAGE('about'),
    },
  },
  {
    id: 'java',
    strategy: 'java',
    pageMarker: frontend('java'),
    files: {
      'pom.xml': JAVA_MAVEN_POM,
      'src/main/java/app/App.java': javaHttpServer('java'),
    },
  },
  {
    id: 'java-gradle',
    strategy: 'java',
    pageMarker: frontend('java-gradle'),
    files: {
      'build.gradle': JAVA_GRADLE_BUILD,
      'src/main/java/app/App.java': javaHttpServer('java-gradle'),
    },
  },
  {
    id: 'node',
    strategy: 'node',
    pageMarker: frontend('node'),
    files: {
      'package.json': pkg({ scripts: { start: 'node index.js' } }),
      'index.js': nodeHttpServer('node'),
    },
  },
  {
    id: 'not-a-web-app',
    strategy: 'fallback',
    files: {
      'package.json': pkg({
        engines: { vscode: '^1.80.0' },
        main: './extension.js',
      }),
    },
  },
  {
    id: 'php',
    strategy: 'php',
    pageMarker: frontend('php'),
    files: {
      'composer.json': composer(),
      'index.php': phpIndex('php'),
    },
  },
  {
    id: 'php-plain',
    strategy: 'php',
    pageMarker: frontend('php-plain'),
    files: { 'index.php': phpIndex('php-plain') },
  },
  {
    id: 'python',
    strategy: 'python',
    pageMarker: frontend('python'),
    files: {
      'requirements.txt': '# stdlib http.server\n',
      'main.py': [
        'from http.server import BaseHTTPRequestHandler, HTTPServer',
        '',
        `HTML = ${JSON.stringify(HTML_PAGE(frontend('python')))}.encode()`,
        '',
        'class Handler(BaseHTTPRequestHandler):',
        '    def do_GET(self):',
        '        self.send_response(200)',
        "        self.send_header('Content-Type', 'text/html; charset=utf-8')",
        "        self.send_header('Content-Length', str(len(HTML)))",
        '        self.end_headers()',
        '        self.wfile.write(HTML)',
        '    def log_message(self, *_args):',
        '        pass',
        '',
        "if __name__ == '__main__':",
        "    HTTPServer(('0.0.0.0', 8000), Handler).serve_forever()",
        '',
      ].join('\n'),
    },
  },
  {
    id: 'ruby',
    strategy: 'ruby',
    files: {
      Gemfile: "source 'https://rubygems.org'\ngem 'rack'\n",
      'config.ru': "run ->(_env) { [200, {}, ['ok']] }\n",
    },
  },
  {
    id: 'rust',
    strategy: 'rust',
    pageMarker: frontend('rust'),
    files: {
      'Cargo.toml': '[package]\nname = "app"\nversion = "0.1.0"\nedition = "2021"\n',
      'src/main.rs': [
        'use std::io::Write;',
        'use std::net::TcpListener;',
        '',
        'fn main() {',
        '    let listener = TcpListener::bind("0.0.0.0:8080").unwrap();',
        `    let body = ${JSON.stringify(HTML_PAGE(frontend('rust')))};`,
        '    let header = format!(',
        '        "HTTP/1.1 200 OK\\r\\nContent-Type: text/html; charset=utf-8\\r\\nContent-Length: {}\\r\\nConnection: close\\r\\n\\r\\n",',
        '        body.len()',
        '    );',
        '    for stream in listener.incoming() {',
        '        if let Ok(mut s) = stream {',
        '            let _ = s.write_all(header.as_bytes());',
        '            let _ = s.write_all(body.as_bytes());',
        '        }',
        '    }',
        '}',
        '',
      ].join('\n'),
    },
  },
  {
    id: 'static',
    strategy: 'static',
    pageMarker: 'pae-frontend:static',
    files: { 'index.html': HTML_PAGE('pae-frontend:static') },
  },
  {
    id: 'adminer',
    strategy: 'php',
    files: {
      'composer.json': composer(),
      'compile.php': PHP,
      'adminer/include/adminer.inc.php': PHP,
      'adminer/drivers/mysql.inc.php': PHP,
    },
  },
  {
    id: 'angular',
    strategy: 'angular',
    pageMarker: frontend('angular'),
    files: {
      'package.json': pkg({
        dependencies: { '@angular/core': '^19.0.0' },
        scripts: { build: 'mkdir -p dist && cp src/index.html dist/index.html' },
      }),
      'angular.json': `${JSON.stringify(
        {
          version: 1,
          projects: {
            app: {
              architect: {
                build: { options: { outputPath: 'dist' } },
              },
            },
          },
        },
        null,
        2
      )}\n`,
      'src/index.html': HTML_PAGE(frontend('angular')),
    },
  },
  {
    id: 'astro',
    strategy: 'astro',
    pageMarker: frontend('astro'),
    files: {
      'package.json': pkg({
        dependencies: { astro: '^5.0.0' },
        scripts: { build: 'astro build' },
      }),
      'astro.config.mjs': 'export default {};\n',
      'src/pages/index.astro':
        '---\n---\n' + HTML_PAGE(frontend('astro')).replace('<!doctype html>', '<!doctype html>\n'),
    },
  },
  {
    id: 'astro-ssr',
    strategy: 'astro',
    pageMarker: frontend('astro-ssr'),
    files: {
      'package.json': pkg({
        type: 'module',
        dependencies: { astro: '^5.0.0', '@astrojs/node': '^9.0.0' },
        scripts: { build: 'astro build', start: 'node ./dist/server/entry.mjs' },
      }),
      'astro.config.mjs': [
        "import node from '@astrojs/node';",
        'export default {',
        "  output: 'server',",
        "  adapter: node({ mode: 'standalone' }),",
        '};',
        '',
      ].join('\n'),
      'src/pages/index.astro':
        '---\n---\n' +
        HTML_PAGE(frontend('astro-ssr')).replace('<!doctype html>', '<!doctype html>\n'),
    },
  },
  {
    id: 'bundler-spa',
    strategy: 'node',
    pageMarker: frontend('bundler-spa'),
    files: {
      'package.json': pkg({
        scripts: { build: 'mkdir -p dist && cp public/index.html dist/index.html' },
      }),
      'public/index.html': HTML_PAGE(frontend('bundler-spa')),
    },
  },
  {
    id: 'chamilo',
    strategy: 'php',
    files: {
      'composer.json': composer({
        php: '>=8.1',
        'chamilo/chamilo-lms': '^2.0',
      }),
      'main/install/index.php': PHP,
    },
  },
  {
    id: 'cra',
    strategy: 'cra',
    pageMarker: frontend('cra'),
    files: {
      'package.json': pkg({
        dependencies: {
          react: '^18.3.0',
          'react-dom': '^18.3.0',
          'react-scripts': '^5.0.0',
        },
        scripts: { build: 'react-scripts build' },
        browserslist: ['defaults'],
      }),
      'public/index.html':
        '<!doctype html><html><head><meta charset="utf-8"><title>cra</title></head><body><p>pae-frontend:cra</p><div id="root"></div></body></html>\n',
      'src/index.js': [
        "import { createRoot } from 'react-dom/client';",
        "createRoot(document.getElementById('root')).render(null);",
        '',
      ].join('\n'),
    },
  },
  {
    id: 'django',
    strategy: 'django',
    pageMarker: frontend('django'),
    files: {
      'requirements.txt': 'django==5.0\n',
      'manage.py': [
        '#!/usr/bin/env python',
        'import os',
        'import sys',
        '',
        'def main():',
        '    os.environ.setdefault("DJANGO_SETTINGS_MODULE", "app.settings")',
        '    from django.core.management import execute_from_command_line',
        '    execute_from_command_line(sys.argv)',
        '',
        "if __name__ == '__main__':",
        '    main()',
        '',
      ].join('\n'),
      'app/__init__.py': '# package\n',
      'app/settings.py': [
        'SECRET_KEY = "test"',
        'DEBUG = True',
        "ALLOWED_HOSTS = ['*']",
        'INSTALLED_APPS = [',
        "    'django.contrib.contenttypes',",
        "    'django.contrib.staticfiles',",
        ']',
        'MIDDLEWARE = []',
        "ROOT_URLCONF = 'app.urls'",
        'TEMPLATES = [{',
        "    'BACKEND': 'django.template.backends.django.DjangoTemplates',",
        "    'DIRS': [],",
        "    'APP_DIRS': True,",
        "    'OPTIONS': {'context_processors': []},",
        '}]',
        'DATABASES = {',
        "    'default': {'ENGINE': 'django.db.backends.sqlite3', 'NAME': 'db.sqlite3'},",
        '}',
        "STATIC_URL = '/static/'",
        "STATIC_ROOT = 'staticfiles'",
        "DEFAULT_AUTO_FIELD = 'django.db.models.BigAutoField'",
        'USE_TZ = True',
        '',
      ].join('\n'),
      'app/urls.py': [
        'from django.http import HttpResponse',
        'from django.urls import path',
        '',
        `HTML = ${JSON.stringify(HTML_PAGE(frontend('django')))}`,
        '',
        'def home(_request):',
        '    return HttpResponse(HTML)',
        '',
        "urlpatterns = [path('', home)]",
        '',
      ].join('\n'),
    },
  },
  {
    id: 'easyappointments',
    strategy: 'php',
    files: {
      'composer.json': composer(),
      'config-sample.php': PHP,
      'application/config/config.php': PHP,
      'system/core/CodeIgniter.php': PHP,
    },
  },
  {
    id: 'express',
    strategy: 'express',
    pageMarker: 'pae-frontend:express',
    files: {
      'package.json': pkg({
        dependencies: { express: '^4.0.0' },
        scripts: { start: 'node index.js' },
      }),
      'index.js': [
        "const express = require('express');",
        'const app = express();',
        'const port = Number(process.env.PORT) || 3000;',
        "app.get('/', (_req, res) => {",
        "  res.type('html').send('<!doctype html><title>express</title><p>pae-frontend:express</p>\\n');",
        '});',
        "app.listen(port, '0.0.0.0');",
        '',
      ].join('\n'),
    },
  },
  {
    id: 'fastify',
    strategy: 'fastify',
    pageMarker: frontend('fastify'),
    files: {
      'package.json': pkg({
        dependencies: { fastify: '^5.0.0' },
        scripts: { start: 'node index.js' },
      }),
      'index.js': [
        "const fastify = require('fastify')();",
        'const port = Number(process.env.PORT) || 3000;',
        "fastify.get('/', async (_req, reply) => {",
        `  reply.type('text/html').send(${JSON.stringify(HTML_PAGE(frontend('fastify')))});`,
        '});',
        "fastify.listen({ port, host: '0.0.0.0' }).catch((error) => {",
        '  console.error(error);',
        '  process.exit(1);',
        '});',
        '',
      ].join('\n'),
    },
  },
  {
    id: 'flarum',
    strategy: 'php',
    files: {
      'composer.json': composer({ php: '>=8.1', 'flarum/core': '^1.0' }),
      flarum: PHP,
      'public/index.php': PHP,
    },
  },
  {
    id: 'laravel',
    strategy: 'laravel',
    files: { 'composer.json': composer(), artisan: `#!/usr/bin/env php\n${PHP}` },
  },
  {
    id: 'magento',
    strategy: 'php',
    files: {
      'composer.json': composer(),
      'bin/magento': PHP,
      'pub/index.php': PHP,
      'app/etc/di.xml': '<config/>\n',
    },
  },
  {
    id: 'mantisbt',
    strategy: 'php',
    files: {
      'composer.json': composer({ php: '>=8.1', 'adodb/adodb-php': '^5.0' }),
      'admin/install.php': PHP,
      'core/database_api.php': PHP,
    },
  },
  {
    id: 'matomo',
    strategy: 'php',
    files: {
      'composer.json': composer(),
      'matomo.php': PHP,
      'piwik.php': PHP,
      'core/Version.php': PHP,
    },
  },
  {
    id: 'nestjs',
    strategy: 'nestjs',
    pageMarker: frontend('nestjs'),
    files: {
      'package.json': pkg({
        dependencies: { '@nestjs/core': '^10.0.0' },
        scripts: {
          build: 'mkdir -p dist && cp src/main.js dist/main.js',
          start: 'node dist/main.js',
        },
      }),
      'nest-cli.json': '{}\n',
      'src/main.js': nodeHttpServer('nestjs'),
    },
  },
  {
    id: 'nextjs',
    strategy: 'nextjs',
    pageMarker: frontend('nextjs'),
    files: {
      'package.json': pkg({
        dependencies: { next: '^15.0.0', react: '^19.0.0', 'react-dom': '^19.0.0' },
        scripts: { build: 'next build', start: 'next start -H 0.0.0.0 -p 3000' },
      }),
      'next.config.js': 'module.exports = { eslint: { ignoreDuringBuilds: true } };\n',
      ...nextAppFiles('nextjs'),
    },
  },
  {
    id: 'nextjs-export',
    strategy: 'nextjs',
    pageMarker: frontend('nextjs-export'),
    files: {
      'package.json': pkg({
        dependencies: { next: '^15.0.0', react: '^19.0.0', 'react-dom': '^19.0.0' },
        scripts: { build: 'next build' },
      }),
      'next.config.js':
        "module.exports = { output: 'export', images: { unoptimized: true }, eslint: { ignoreDuringBuilds: true } };\n",
      ...nextAppFiles('nextjs-export'),
    },
  },
  {
    id: 'nuxt',
    strategy: 'nuxt',
    pageMarker: frontend('nuxt'),
    files: {
      'package.json': pkg({
        type: 'module',
        dependencies: { nuxt: '^3.0.0', vue: '^3.0.0' },
        scripts: { build: 'nuxt build' },
      }),
      'nuxt.config.js': 'export default defineNuxtConfig({ ssr: true });\n',
      'app.vue': `<template>\n  <p>${frontend('nuxt')}</p>\n</template>\n`,
    },
  },
  {
    id: 'opencart',
    strategy: 'php',
    files: {
      'composer.json': composer(),
      'upload/system/startup.php': PHP,
      'upload/admin/index.php': PHP,
      'upload/install/cli_install.php': PHP,
    },
  },
  {
    id: 'osticket',
    strategy: 'php',
    files: {
      'bootstrap.php': PHP,
      'include/class.osticket.php': PHP,
      'include/ost-sampleconfig.php': PHP,
      'setup/install.php': PHP,
    },
  },
  {
    id: 'passbolt',
    strategy: 'php',
    files: {
      'config/passbolt.default.php': PHP,
      'config/app.default.php': PHP,
      'webroot/index.php': PHP,
    },
  },
  {
    id: 'phpbb',
    strategy: 'php',
    files: {
      'phpBB/composer.json': composer(),
      'phpBB/includes/constants.php': PHP,
      'phpBB/viewforum.php': PHP,
    },
  },
  {
    id: 'phpmyadmin',
    strategy: 'php',
    files: {
      'composer.json': composer({
        php: '>=8.1',
        'phpmyadmin/sql-parser': '^5.0',
        'phpmyadmin/motranslator': '^5.0',
      }),
      'config.sample.inc.php': PHP,
    },
  },
  {
    id: 'rails',
    strategy: 'rails',
    files: {
      Gemfile: "source 'https://rubygems.org'\ngem 'rails'\n",
      'config/application.rb': 'module App\n  class Application\n  end\nend\n',
    },
  },
  {
    id: 'remix',
    strategy: 'remix',
    pageMarker: frontend('remix'),
    files: {
      'package.json': pkg({
        dependencies: {
          '@remix-run/node': '^2.0.0',
          '@remix-run/react': '^2.0.0',
          '@remix-run/serve': '^2.0.0',
        },
        scripts: {
          build: 'mkdir -p build/server && cp server.js build/server/index.js',
          start: 'node build/server/index.js',
        },
      }),
      'remix.config.js': 'module.exports = {};\n',
      'server.js': nodeHttpServer('remix'),
    },
  },
  {
    id: 'suitecrm',
    strategy: 'php',
    files: {
      'composer.json': composer(),
      'suitecrm_version.php': PHP,
      'install.php': PHP,
      'include/entryPoint.php': PHP,
    },
  },
  {
    id: 'sveltekit',
    strategy: 'sveltekit',
    pageMarker: frontend('sveltekit'),
    files: {
      'package.json': pkg({
        type: 'module',
        dependencies: {
          '@sveltejs/kit': '2.16.1',
          '@sveltejs/adapter-node': '5.2.11',
          svelte: '5.16.0',
          vite: '6.0.11',
        },
        scripts: { build: 'vite build', start: 'node build/index.js' },
      }),
      'svelte.config.js': [
        "import adapter from '@sveltejs/adapter-node';",
        'export default { kit: { adapter: adapter() } };',
        '',
      ].join('\n'),
      'vite.config.js': [
        "import { sveltekit } from '@sveltejs/kit/vite';",
        'export default { plugins: [sveltekit()] };',
        '',
      ].join('\n'),
      'src/app.html': [
        '<!doctype html>',
        '<html>',
        '<head><meta charset="utf-8">%sveltekit.head%</head>',
        `<body><p>${frontend('sveltekit')}</p><div style="display:contents">%sveltekit.body%</div></body>`,
        '</html>',
        '',
      ].join('\n'),
      'src/routes/+page.svelte': `<p>${frontend('sveltekit')}</p>\n`,
    },
  },
  {
    id: 'sveltekit-static',
    strategy: 'sveltekit',
    pageMarker: frontend('sveltekit-static'),
    files: {
      'package.json': pkg({
        type: 'module',
        dependencies: {
          '@sveltejs/kit': '2.16.1',
          svelte: '5.16.0',
          '@sveltejs/adapter-static': '3.0.8',
          vite: '6.0.11',
        },
        scripts: { build: 'vite build' },
      }),
      'svelte.config.js': [
        "import adapter from '@sveltejs/adapter-static';",
        'export default { kit: { adapter: adapter({ pages: "build", assets: "build" }) } };',
        '',
      ].join('\n'),
      'vite.config.js': [
        "import { sveltekit } from '@sveltejs/kit/vite';",
        'export default { plugins: [sveltekit()] };',
        '',
      ].join('\n'),
      'src/app.html': [
        '<!doctype html>',
        '<html>',
        '<head><meta charset="utf-8">%sveltekit.head%</head>',
        `<body><p>${frontend('sveltekit-static')}</p><div style="display:contents">%sveltekit.body%</div></body>`,
        '</html>',
        '',
      ].join('\n'),
      'src/routes/+layout.js': 'export const prerender = true;\n',
      'src/routes/+page.svelte': `<p>${frontend('sveltekit-static')}</p>\n`,
    },
  },
  {
    id: 'tanstack-start',
    strategy: 'tanstack-start',
    pageMarker: frontend('tanstack-start'),
    files: {
      'package.json': pkg({
        type: 'module',
        dependencies: { '@tanstack/react-start': '^1.0.0' },
        scripts: { build: 'mkdir -p .output/server && cp server.mjs .output/server/index.mjs' },
      }),
      'server.mjs': nodeHttpServerEsm('tanstack-start'),
    },
  },
  {
    id: 'vite',
    strategy: 'vite',
    pageMarker: 'pae-frontend:vite',
    files: {
      'package.json': pkg({
        dependencies: { vite: '^6.0.0' },
        scripts: { build: 'vite build' },
      }),
      'vite.config.js': "export default { build: { outDir: 'dist', emptyOutDir: true } };\n",
      'index.html':
        '<!doctype html><html><head><meta charset="utf-8"><title>vite</title></head><body><p>pae-frontend:vite</p><script type="module" src="/src/main.js"></script></body></html>\n',
      'src/main.js': "document.querySelector('p')?.setAttribute('data-hydrated', '1');\n",
    },
  },
  {
    id: 'wordpress',
    strategy: 'php',
    files: {
      'wp-settings.php': PHP,
      'wp-login.php': PHP,
      'wp-includes/version.php': PHP,
      'wp-admin/index.php': PHP,
    },
  },
  {
    id: 'wordpress-develop',
    strategy: 'php',
    files: {
      'src/wp-settings.php': PHP,
      'src/wp-includes/version.php': PHP,
      'Gruntfile.js': 'module.exports = function () {};\n',
      'package.json': pkg({ scripts: { build: 'grunt' } }),
    },
  },
];

export function fixtureIds(): string[] {
  return DEPLOY_KIND_FIXTURES.map((fixture) => fixture.id).sort();
}

export function fixtureById(id: string): DeployKindFixture | undefined {
  return DEPLOY_KIND_FIXTURES.find((fixture) => fixture.id === id);
}

export type WebDeployFixture = DeployKindFixture & { pageMarker: string };

/** Trees that are real web frontends and are deployed + fetched over HTTP. */
export function webDeployFixtures(): WebDeployFixture[] {
  return DEPLOY_KIND_FIXTURES.filter(
    (fixture): fixture is WebDeployFixture =>
      typeof fixture.pageMarker === 'string' && fixture.pageMarker.length > 0
  );
}

function yamlStem(name: string): boolean {
  return name.endsWith('.yaml') && !name.startsWith('_');
}

/** Platform ids shipped in this checkout, or null when `core/` is not readable. */
export function shippedPlatformIds(): string[] | null {
  if (!fs.existsSync(PLATFORMS_DIR)) {
    return null;
  }
  return fs
    .readdirSync(PLATFORMS_DIR)
    .filter(yamlStem)
    .map((name) => name.replace(/\.yaml$/, ''))
    .sort();
}

/** App recipe ids shipped in this checkout, or null when `core/` is not readable. */
export function shippedAppIds(): string[] | null {
  if (!fs.existsSync(APPS_DIR)) {
    return null;
  }
  return fs
    .readdirSync(APPS_DIR)
    .filter((name) => fs.existsSync(path.join(APPS_DIR, name, 'panelalpha.yaml')))
    .sort();
}
