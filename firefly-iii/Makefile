PHP ?= php
HOST ?= 127.0.0.1
PORT ?= 8000

.PHONY: up dev

up:
	$(PHP) artisan serve --host=$(HOST) --port=$(PORT)

dev: up
