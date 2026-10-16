## 1.2.1 (16-10-2026)

- Gracefully stop an idle queue worker before scaling its Heroku formation to zero, preventing it from claiming another job during shutdown
