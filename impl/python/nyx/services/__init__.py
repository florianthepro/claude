"""Dienste auf dem gemeinsamen Kanal.

Chat, Post, Dateien und Anrufe sind keine getrennten Systeme. Sie benutzen
dieselbe Identitaet, dieselbe Ratsche und dieselben blinden Ablagen und
unterscheiden sich nur im Umschlag — dem ersten Feld des Klartexts.

Das ist keine Sparsamkeit, sondern eine Sicherheitseigenschaft: ein
Beobachter kann an der Ablage nicht erkennen, ob dort eine Kurznachricht,
eine Datei oder das Signalisieren eines Anrufs liegt. Alle Teile sind
gleich gross, alle Tags sehen gleich aus.
"""

from .calls import CallService, CallState
from .chat import ChatService
from .files import FileService
from .mail import MailService

ENVELOPE_TYPES = ("chat", "receipt", "mail", "file", "call")

__all__ = ["CallService", "CallState", "ChatService", "FileService",
           "MailService", "ENVELOPE_TYPES"]
