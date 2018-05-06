# Webpack environment

This is an extension to [nemo64/environment] which adds a default webpack file and some configuration to the Makefile and docker configuration to easily set up a webpack in your php project.

There aren't any further local dependencies. Every node execution is run through docker.

## How to install

Just run `composer require --dev nemo64/webpack-environment`.

## How it works

Webpack will be executed as a docker service.

```YAML
services:
  webpack:
    image: 'node:carbon'
    command: 'yarn run encore dev --watch'
    volumes:
      - '.:/var/www'
    working_dir: /var/www
```

The makefile will be extended with this install job

```Makefile
node_modules: docker-compose.log $(wildcard package.* yarn.*)
	docker-compose run --rm --no-deps webpack yarn install
```

[nemo64/environment]: https://github.com/Nemo64/environment